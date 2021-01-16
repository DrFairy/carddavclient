<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (C) 2020 Michael Stilkerich <ms@mike2k.de>
 *
 * This file is part of PHP-CardDavClient.
 *
 * PHP-CardDavClient is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PHP-CardDavClient is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PHP-CardDavClient.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Class CardDavClient
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use Psr\Http\Message\ResponseInterface as Psr7Response;
use MStilkerich\CardDavClient\XmlElements\Multistatus;
use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;
use MStilkerich\CardDavClient\XmlElements\Deserializers;
use MStilkerich\CardDavClient\Exception\XmlParseException;

/*
Other needed features:
  - Setting extra headers (Depth, Content-Type, charset, If-Match, If-None-Match)
  - Debug output HTTP traffic to logfile
 */
class CardDavClient
{
    /********* CONSTANTS *********/
    private const MAP_NS2PREFIX = [
        XmlEN::NSDAV => 'DAV',
        XmlEN::NSCARDDAV => 'CARDDAV',
        XmlEN::NSCS => 'CS',
    ];

    /********* PROPERTIES *********/
    /** @var string */
    protected $base_uri;

    /** @var HttpClientAdapter */
    protected $httpClient;

    /********* PUBLIC FUNCTIONS *********/
    public function __construct(string $base_uri, string $username, string $password)
    {
        $this->base_uri = $base_uri;
        $this->httpClient = new HttpClientAdapterGuzzle($base_uri, $username, $password);
    }

    /**
     * Note: Google's server does not accept an empty syncToken, though explicitly allowed for initial sync by RFC6578.
     * It will respond with 400 Bad Request and error message "Request contains an invalid argument."
     *
     * The Google issues have been reported to Google: https://issuetracker.google.com/issues/160190530
     */
    public function syncCollection(string $addressbookUri, string $syncToken): Multistatus
    {
        $srv = self::getParserService();
        $body = $srv->write(XmlEN::REPORT_SYNCCOLL, [
            XmlEN::SYNCTOKEN => $syncToken,
            XmlEN::SYNCLEVEL => "1",
            XmlEN::PROP => [ XmlEN::GETETAG => null ]
        ]);

        // RFC6578: Depth: 0 header is required for sync-collection report
        // Google requires a Depth: 1 header or the REPORT will only target the collection itself
        // This hack seems to be the simplest solution to behave RFC-compliant in general but have Google work
        // nonetheless
        if (strpos(self::concatUrl($this->base_uri, $addressbookUri), "www.googleapis.com") !== false) {
            $depthValue = "1";
        } else {
            $depthValue = "0";
        }

        $response = $this->httpClient->sendRequest('REPORT', $addressbookUri, [
            "headers" =>
            [
                "Depth" => $depthValue,
                "Content-Type" => "application/xml; charset=UTF-8"
            ],
            "body" => $body
        ]);

        return self::checkAndParseXMLMultistatus($response);
    }

    public function getResource(string $uri): Psr7Response
    {
        $response = $this->httpClient->sendRequest('GET', $uri);
        self::assertHttpStatus($response, 200, 200, "GET $uri");

        $body = (string) $response->getBody();
        if (empty($body)) {
            throw new \Exception("Response to GET $uri request does not include a body");
        }

        return $response;
    }

    public function getAddressObject(string $uri): array
    {
        $response = $this->getResource($uri);

        // presence of this header is required per RFC6352:
        // "A response to a GET request targeted at an address object resource MUST contain an ETag response header
        // field indicating the current value of the strong entity tag of the address object resource."
        $etag = $response->getHeaderLine("ETag");
        if (empty($etag)) {
            throw new \Exception("Response to address object $uri GET request does not include ETag header");
        }

        $body = (string) $response->getBody(); // checked to be present in getResource()
        return [ 'etag' => $etag, 'vcf' => $body ];
    }

    /**
     * Requests the server to delete the given resource.
     */
    public function deleteResource(string $uri): void
    {
        $response = $this->httpClient->sendRequest('DELETE', $uri);
        self::assertHttpStatus($response, 200, 204, "DELETE $uri");
    }

    /**
     * Requests the server to update the given resource.
     *
     * Normally, the ETag of the existing expected server-side resource should be given to make the update
     * conditional on that no other changes have been done to the server-side resource, otherwise lost updates might
     * occur. However, if no ETag is given, the server-side resource is overwritten unconditionally.
     *
     * @return ?string
     *  ETag of the updated resource, an empty string if no ETag was given by the server, or null if the update failed
     *  because the server-side ETag did not match the given one.
     */
    public function updateResource(string $body, string $uri, string $etag = ""): ?string
    {
        $headers = [ "Content-Type" => "text/vcard" ];
        if (!empty($etag)) {
            $headers["If-Match"] = $etag;
        }

        $response = $this->httpClient->sendRequest(
            'PUT',
            $uri,
            [
                "headers" => $headers,
                "body" => $body
            ]
        );

        $status = $response->getStatusCode();

        if ($status == 412) {
            $etag = null;
        } else {
            self::assertHttpStatus($response, 200, 204, "PUT $uri");
            $etag = $response->getHeaderLine("ETag");
        }

        return $etag;
    }

    /**
     * Requests the server to create the given resource.
     *
     * @param bool $post If true
     *
     * @return string[]
     *  Associative array with keys
     *   - uri (string): URI of the new resource if the request was successful
     *   - etag (string): Entity tag of the created resource if returned by server, otherwise empty string.
     */
    public function createResource(string $body, string $suggestedUri, bool $post = false): array
    {
        $uri = $suggestedUri;
        $attempt = 0;

        $headers = [ "Content-Type" => "text/vcard" ];
        if ($post) {
            $reqtype = 'POST';
            $retryLimit = 1;
        } else {
            $reqtype = 'PUT';
            // for PUT, we have to guess a free URI, so we give it several tries
            $retryLimit = 5;
            $headers["If-None-Match"] = "*";
        }

        do {
            ++$attempt;
            $response = $this->httpClient->sendRequest(
                $reqtype,
                $uri,
                [ "headers" => $headers, "body" => $body ]
            );

            $status = $response->getStatusCode();
            // 201 -> New resource created
            // 200/204 -> Existing resource modified (should not happen b/c of If-None-Match
            // 412 -> Precondition failed
            if ($status == 412) {
                // make up a new random filename until retry limit is hit (append a random integer to the suggested
                // filename, e.g. /newcard.vcf could become /newcard-1234.vcf)
                $randint = rand();
                $uri = preg_replace("/(\.[^.]*)?$/", "-$randint$0", $suggestedUri, 1);
            }
        } while (($status == 412) && ($attempt < $retryLimit));

        self::assertHttpStatus($response, 201, 201, "$reqtype $suggestedUri");

        $etag = $response->getHeaderLine("ETag");
        if ($post) {
            $uri = $response->getHeaderLine("Location");
        }
        return [ 'uri' => $uri, 'etag' => $etag ];
    }

    /**
     * @psalm-return Multistatus<XmlElements\ResponsePropstat>
     */
    public function multiGet(
        string $addressbookUri,
        array $requestedUris,
        array $requestedVCardProps = []
    ): Multistatus {
        $srv = self::getParserService();

        // Determine the prop element for the report
        $reqprops = [
            XmlEN::GETETAG => null,
            XmlEN::ADDRDATA => $this->determineReqCardProps($requestedVCardProps)
        ];

        $body = $srv->write(
            XmlEN::REPORT_MULTIGET,
            array_merge(
                [ [ 'name' => XmlEN::PROP, 'value' => $reqprops ] ],
                array_map(
                    function (string $uri): array {
                        return [ 'name' => XmlEN::HREF, 'value' => $uri ];
                    },
                    $requestedUris
                )
            )
        );

        $response = $this->httpClient->sendRequest('REPORT', $addressbookUri, [
            "headers" =>
            [
                // RFC6352: Depth: 0 header is required for addressbook-multiget report.
                "Depth" => 0,
                "Content-Type" => "application/xml; charset=UTF-8"
            ],
            "body" => $body
        ]);

        return self::checkAndParseXMLMultistatus($response, XmlElements\ResponsePropstat::class);
    }

    /**
     * Issues an addressbook-query report.
     *
     * @param string $addressbookUri The URI of the addressbook collection to query
     * @param QueryConditions $conditions The query filter conditions
     * @param list<string> $requestedVCardProps A list of the requested VCard properties. If empty array, the full
     *                                          VCards are requested from the server.
     * @param bool $allConditionsMustMatch Whether all or any of the conditions needs to match.
     * @psalm-return Multistatus
     */
    public function query(
        string $addressbookUri,
        QueryConditions $conditions,
        array $requestedVCardProps,
        int $limit
    ): Multistatus {
        $srv = self::getParserService();

        // Determine the prop element for the report
        $reqprops = [
            XmlEN::GETETAG => null,
            XmlEN::ADDRDATA => $this->determineReqCardProps($requestedVCardProps)
        ];

        $body = $srv->write(
            XmlEN::REPORT_QUERY,
            [
                [ 'name' => XmlEN::PROP, 'value' => $reqprops ],
                [
                    'name' => XmlEN::FILTER,
                    'attributes' => $conditions->toFilterAttributes(),
                    'value' => $conditions->toFilterElements()
                ],
                // FIXME [ 'name' => XmlEN::LIMIT, 'value' => [ name => XmlEN::NRESULTS, value => "5" ] ],
            ]
        );

        $response = $this->httpClient->sendRequest('REPORT', $addressbookUri, [
            "headers" =>
            [
                // RFC6352: Depth: 1 header sets query scope to the addressbook collection
                "Depth" => 1,
                "Content-Type" => "application/xml; charset=UTF-8"
            ],
            "body" => $body
        ]);

        return self::checkAndParseXMLMultistatus($response, XmlElements\ResponsePropstat::class);
    }

    /**
     * Builds a CARDDAV::address-data element with the requested properties.
     *
     * If no properties are requested, returns null - an empty address-data element means that the full VCards shall be
     * returned.
     *
     * Some properties that are mandatory are added to the list.
     *
     * @param list<string> $requestedVCardProps as list of the VCard properties requested by the user.
     * @return null|list<array{name: string, attributes: array{name: string}}>
     */
    private function determineReqCardProps(array $requestedVCardProps): ?array
    {
        if (empty($requestedVCardProps)) {
            return null;
        }

        $requestedVCardProps = self::addRequiredVCardProperties($requestedVCardProps);

        $reqprops = array_map(
            function (string $prop): array {
                return [
                    'name' => XmlEN::VCFPROP,
                    'attributes' => [ 'name' => $prop ]
                ];
            },
            $requestedVCardProps
        );

        return $reqprops;
    }

    // $props is either a single property or an array of properties
    // Namespace shortcuts: DAV for DAV, CARDDAV for the CardDAV namespace
    // RFC4918: There is always only a single value for a property, which is an XML fragment
    public function findProperties(
        string $uri,
        array $props,
        string $depth = "0"
    ): array {
        $srv = self::getParserService();
        $body = $srv->write(XmlEN::PROPFIND, [
            XmlEN::PROP => array_fill_keys($props, null)
        ]);

        $result = $this->requestWithRedirectionTarget(
            'PROPFIND',
            $uri,
            [
                "headers" =>
                [
                    // RFC4918: A client MUST submit a Depth header with a value of "0", "1", or "infinity"
                    "Depth" => $depth,
                    "Content-Type" => "application/xml; charset=UTF-8",
                    // Prefer: reduce reply size if supported, see RFC8144
                    "Prefer" => "return=minimal"
                ],
                "body" => $body
            ]
        );

        $multistatus = self::checkAndParseXMLMultistatus($result["response"], XmlElements\ResponsePropstat::class);

        $resultProperties = [];

        foreach ($multistatus->responses as $response) {
            $href = $response->href;

            // There may have been redirects involved in querying the properties, particularly during addressbook
            // discovery. They may even point to a different server than the original request URI. Return absolute URL
            // in the responses to allow the caller to know the actual location on that the properties where reported
            $respUri = self::concatUrl($result["location"], $href);

            if (!empty($response->propstat)) {
                foreach ($response->propstat as $propstat) {
                    if (stripos($propstat->status, " 200 ") !== false) {
                        $resultProperties[] = [ 'uri' => $respUri, 'props' => $propstat->prop->props ];
                    }
                }
            }
        }

        return $resultProperties;
    }

    /********* PRIVATE FUNCTIONS *********/

    private static function addRequiredVCardProperties(array $requestedVCardProps): array
    {
        $minimumProps = [ 'BEGIN', 'END', 'FN', 'VERSION', 'UID' ];
        foreach ($minimumProps as $prop) {
            if (!in_array($prop, $requestedVCardProps)) {
                $requestedVCardProps[] = $prop;
            }
        }

        return $requestedVCardProps;
    }

    private static function assertHttpStatus(Psr7Response $davReply, int $minCode, int $maxCode, string $nfo): void
    {
        $status = $davReply->getStatusCode();

        if (($status < $minCode) || ($status > $maxCode)) {
            $reason = $davReply->getReasonPhrase();
            $body = (string) $davReply->getBody();

            throw new \Exception("$nfo HTTP request was not successful ($status $reason): $body");
        }
    }

    /**
     * @template RT of XmlElements\Response
     * @psalm-param class-string<RT> $responseType
     * @return MultiStatus<RT>
     */
    private static function checkAndParseXMLMultistatus(
        Psr7Response $davReply,
        string $responseType = XmlElements\Response::class
    ): Multistatus {
        $multistatus = null;

        self::assertHttpStatus($davReply, 207, 207, "Expected Multistatus");
        if (preg_match(';(?i)(text|application)/xml;', $davReply->getHeaderLine('Content-Type'))) {
            $service = self::getParserService();
            $multistatus = $service->expect(XmlEN::MULTISTATUS, (string) $davReply->getBody());
        }

        if (!($multistatus instanceof Multistatus)) {
            throw new XmlParseException("Response is not the expected Multistatus response.");
        }

        foreach ($multistatus->responses as $response) {
            if (!($response instanceof $responseType)) {
                throw new XmlParseException("Multistatus contains unexpected responses (Expected: $responseType)");
            }
        }

        return $multistatus;
    }

    private function requestWithRedirectionTarget(string $method, string $uri, array $options = []): array
    {
        $options['allow_redirects'] = false;

        $redirAttempt = 0;
        $redirLimit = 5;

        $uri = $this->absoluteUrl($uri);

        do {
            $response = $this->httpClient->sendRequest($method, $uri, $options);
            $scode = $response->getStatusCode();

            // 301 Moved Permanently
            // 308 Permanent Redirect
            // 302 Found
            // 307 Temporary Redirect
            $isRedirect = (($scode == 301) || ($scode == 302) || ($scode == 307) || ($scode == 308));

            if ($isRedirect && $response->hasHeader('Location')) {
                $uri = self::concatUrl($uri, $response->getHeaderLine('Location'));
                $redirAttempt++;
            } else {
                break;
            }
        } while ($redirAttempt < $redirLimit);

        return [
            "redirected" => ($redirAttempt == 0),
            "location" => $uri,
            "response" => $response
        ];
    }

    public function absoluteUrl(string $relurl): string
    {
        return self::concatUrl($this->base_uri, $relurl);
    }

    public static function concatUrl(string $baseurl, string $relurl): string
    {
        return \Sabre\Uri\resolve($baseurl, $relurl);
    }

    public static function compareUrlPaths(string $url1, string $url2): bool
    {
        $comp1 = \Sabre\Uri\parse($url1);
        $comp2 = \Sabre\Uri\parse($url2);
        $p1 = rtrim($comp1["path"], "/");
        $p2 = rtrim($comp2["path"], "/");
        return $p1 === $p2;
    }

    private static function getParserService(): \Sabre\Xml\Service
    {

        $service = new \Sabre\Xml\Service();
        $service->namespaceMap = self::MAP_NS2PREFIX;
        $service->elementMap = [
            XmlEN::MULTISTATUS => XmlElements\Multistatus::class,
            XmlEN::RESPONSE => XmlElements\Response::class,
            XmlEN::PROPSTAT => XmlElements\Propstat::class,
            XmlEN::PROP => XmlElements\Prop::class,
            XmlEN::ABOOK_HOME => [ Deserializers::class, 'deserializeHrefMulti' ],
            XmlEN::RESTYPE => '\Sabre\Xml\Deserializer\enum',
            XmlEN::SUPPORTED_REPORT_SET => [ Deserializers::class, 'deserializeSupportedReportSet' ],
            XmlEN::ADD_MEMBER => [ Deserializers::class, 'deserializeHrefSingle' ],
            XmlEN::CURUSRPRINC => [ Deserializers::class, 'deserializeHrefSingle' ]
        ];

        return $service;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
