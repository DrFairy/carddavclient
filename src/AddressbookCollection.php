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

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use Sabre\VObject\UUIDUtil;
use Sabre\VObject\Component\VCard;
use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;
use MStilkerich\CardDavClient\XmlElements\{Filter,ResponsePropstat,ResponseStatus};

/**
 * Represents an addressbook collection on a WebDAV server.
 *
 * @psalm-import-type SimpleConditions from Filter
 * @psalm-import-type ComplexConditions from Filter
 *
 * @psalm-type VcardValidateResult = array {
 *   level: int,
 *   message: string,
 *   node: \Sabre\VObject\Component | \Sabre\VObject\Property
 * }
 */
class AddressbookCollection extends WebDavCollection
{
    /**
     * List of properties to query in refreshProperties() and returned by getProperties().
     * @psalm-var list<string>
     * @see WebDavResource::getProperties()
     * @see WebDavResource::refreshProperties()
     */
    private const PROPNAMES = [
        XmlEN::DISPNAME,
        XmlEN::GETCTAG,
        XmlEN::SUPPORTED_ADDRDATA,
        XmlEN::ABOOK_DESC,
        XmlEN::MAX_RESSIZE
    ];

    /**
     * Returns a displayname for the addressbook.
     *
     * If a server-side displayname exists in the DAV:displayname property, it is returned. Otherwise, the last
     * component of the URL is returned. This is suggested by RFC6352 to compose the addressbook name.
     *
     * @return string Name of the addressbook
     */
    public function getName(): string
    {
        $props = $this->getProperties();
        return $props[XmlEN::DISPNAME] ?? basename($this->uri);
    }

    /**
     * Provides a stringified representation of this addressbook (name and URI).
     *
     * Note that the result of this function is meant for display, not parsing. Thus the content and formatting of the
     * text may change without considering backwards compatibility.
     */
    public function __toString(): string
    {
        $desc  = $this->getName() . " (" . $this->uri . ")";
        return $desc;
    }

    /**
     * Provides the details for this addressbook as printable text.
     *
     * Note that the result of this function is meant for display, not parsing. Thus the content and formatting of the
     * text may change without considering backwards compatibility.
     */
    public function getDetails(): string
    {
        $desc  = "Addressbook " . $this->getName() . "\n";
        $desc .= "    URI: " . $this->uri . "\n";

        $props = $this->getProperties();
        foreach ($props as $propName => $propVal) {
            $desc .= "    " . $this->shortenXmlNamespacesForPrinting($propName) . ": ";

            switch (gettype($propVal)) {
                case 'integer':
                case 'string':
                    $desc .= $this->shortenXmlNamespacesForPrinting((string) $propVal);
                    break;

                case 'array':
                    // can be list of strings or list of array<string,string>
                    $sep = "";
                    foreach ($propVal as $v) {
                        $desc .= $sep;
                        $sep = ", ";

                        if (is_string($v)) {
                            $desc .= $this->shortenXmlNamespacesForPrinting($v);
                        } else {
                            $strings = [];
                            $fields = array_keys($v);
                            sort($fields);
                            foreach ($fields as $f) {
                                $strings[] = "$f: $v[$f]";
                            }
                            $desc .= '[' . implode(', ', $strings) . ']';
                        }
                    }
                    break;

                default:
                    $desc .= print_r($propVal, true);
                    break;
            }

            $desc .= "\n";
        }

        return $desc;
    }

    public function supportsMultiGet(): bool
    {
        return $this->supportsReport(XmlEN::REPORT_MULTIGET);
    }

    public function getCTag(): ?string
    {
        $props = $this->getProperties();
        return $props[XmlEN::GETCTAG] ?? null;
    }

    /**
     * Retrieves an address object from the addressbook collection and parses it to a VObject.
     *
     * @param string $uri
     *  URI of the address object to fetch
     * @psalm-return array{vcard: VCard, etag: string, vcf: string}
     * @return array<string,mixed> Associative array with keys
     *   - etag(string): Entity tag of the returned card
     *   - vcf(string): VCard as string
     *   - vcard(VCard): VCard as Sabre/VObject VCard
     */
    public function getCard(string $uri): array
    {
        $client = $this->getClient();
        $response = $client->getAddressObject($uri);
        $vcard = \Sabre\VObject\Reader::read($response["vcf"]);
        if (!($vcard instanceof VCard)) {
            throw new \Exception("Parsing of string did not result in a VCard object: {$response["vcf"]}");
        }
        $response["vcard"] = $vcard;
        return $response;
    }

    /**
     * Deletes a VCard from the addressbook.
     *
     * @param string $uri The URI of the VCard to be deleted.
     */
    public function deleteCard(string $uri): void
    {
        $client = $this->getClient();
        $client->deleteResource($uri);
    }

    /**
     * Creates a new VCard in the addressbook.
     *
     * If the given VCard lacks the mandatory UID property, one will be generated. If the server provides an add-member
     * URI, the new card will be POSTed to that URI. Otherwise, the function attempts to store the card do a URI whose
     * last path component (filename) is derived from the UID of the VCard.
     *
     * @param VCard $vcard The VCard to be stored.
     * @return array{uri: string, etag: string}
     *  Associative array with keys
     *   - uri (string): URI of the new resource if the request was successful
     *   - etag (string): Entity tag of the created resource if returned by server, otherwise empty string.
     */
    public function createCard(VCard $vcard): array
    {
        $props = $this->getProperties();

        // Add UID if not present
        if (empty($vcard->select("UID"))) {
            $uid = UUIDUtil::getUUID();
            Config::$logger->notice("Adding missing UID property to new VCard ($uid)");
            $vcard->UID = $uid;
        } else {
            $uid = (string) $vcard->UID;
            // common case for v4 vcards where UID must be a URI
            $uid = str_replace("urn:uuid:", "", $uid);
        }

        // Assert validity of the Card for CardDAV, including valid UID property
        $this->validateCard($vcard);

        $client = $this->getClient();

        $addMemberUrl = $props[XmlEN::ADD_MEMBER] ?? null;

        if (isset($addMemberUrl)) {
            $newResInfo = $client->createResource(
                $vcard->serialize(),
                $client->absoluteUrl($addMemberUrl),
                true
            );
        } else {
            // restrict to allowed characters
            $name = preg_replace('/[^A-Za-z0-9._-]/', '-', $uid);
            $newResInfo = $client->createResource(
                $vcard->serialize(),
                $client->absoluteUrl("$name.vcf")
            );
        }

        return $newResInfo;
    }

    /**
     * Updates an existing VCard of the addressbook.
     *
     * The update request to the server will be made conditional depending on that the provided ETag value of the card
     * matches that on the server, meaning that the card has not been changed on the server in the meantime.
     *
     * @param string $uri The URI of the card to update.
     * @param VCard $vcard The updated VCard to be stored.
     * @param string $etag The ETag of the card that was originally retrieved and modified.
     * @return ?string Returns the ETag of the updated card if provided by the server, null otherwise. If null is
     *                 returned, it must be assumed that the server stored the card with modifications and the card
     *                 should be read back from the server (this is a good idea anyway).
     */
    public function updateCard(string $uri, VCard $vcard, string $etag): ?string
    {
        // Assert validity of the Card for CardDAV, including valid UID property
        $this->validateCard($vcard);

        $client = $this->getClient();
        $etag = $client->updateResource($vcard->serialize(), $uri, $etag);

        return $etag;
    }

    /**
     * Issues an addressbook-query report.
     *
     * @param SimpleConditions|ComplexConditions $conditions The query filter conditions, see Filter class for format.
     * @param list<string> $requestedVCardProps A list of the requested VCard properties. If empty array, the full
     *                                          VCards are requested from the server.
     * @param bool $matchAll Whether all or any of the conditions needs to match.
     * @param int $limit Tell the server to return at most $limit results. 0 means no limit.
     *
     * @return array<string, array{vcard: VCard, etag: string}>
     *
     * @see Filter
     * @since v1.1.0
     */
    public function query(
        array $conditions,
        array $requestedVCardProps = [],
        bool $matchAll = false,
        int $limit = 0
    ) {
        $conditions = new Filter($conditions, $matchAll);
        $client = $this->getClient();
        $multistatus = $client->query($this->uri, $conditions, $requestedVCardProps, $limit);

        $results = [];
        foreach ($multistatus->responses as $response) {
            if ($response instanceof ResponsePropstat) {
                $respUri = $response->href;

                foreach ($response->propstat as $propstat) {
                    if (stripos($propstat->status, " 200 ") !== false) {
                        Config::$logger->debug("VCF for $respUri received via query");
                        $vcf = $propstat->prop->props[XmlEN::ADDRDATA] ?? "";
                        $vcard = \Sabre\VObject\Reader::read($vcf);
                        if ($vcard instanceof VCard) {
                            $results[$respUri] = [
                                "etag" => $propstat->prop->props[XmlEN::GETETAG] ?? "",
                                "vcard" => $vcard
                            ];
                        } else {
                            Config::$logger->error("sabre reader did not return a VCard object for $vcf\n");
                        }
                    }
                }
            } elseif ($response instanceof ResponseStatus) {
                foreach ($response->hrefs as $respUri) {
                    if (CardDavClient::compareUrlPaths($respUri, $this->uri)) {
                        if (stripos($response->status, " 507 ") !== false) {
                            // results truncated by server
                        } else {
                            Config::$logger->debug(__METHOD__ . " Ignoring response on addressbook itself");
                        }
                    } else {
                        Config::$logger->warning(__METHOD__ . " Unexpected respstatus element {$response->status}");
                    }
                }
            }
        }

        return $results;
    }

    /**
     * This function replaces some well-known XML namespaces with a long name with shorter names for printing.
     */
    protected function shortenXmlNamespacesForPrinting(string $s): string
    {
        return str_replace(
            [ "{" . XmlEN::NSCARDDAV . "}", "{" . XmlEN::NSCS . "}" ],
            [ "{CARDDAV}", "{CS}" ],
            $s
        );
    }

    /**
     * Validates a VCard before sending it to a CardDAV server.
     *
     * @param VCard $vcard The VCard to be validated.
     */
    protected function validateCard(VCard $vcard): void
    {
        $hasError = false;
        $errors = "";

        // Assert validity of the Card for CardDAV, including valid UID property
        /** @var list<VcardValidateResult> */
        $validityIssues = $vcard->validate(\Sabre\VObject\Node::PROFILE_CARDDAV | \Sabre\VObject\Node::REPAIR);
        foreach ($validityIssues as $issue) {
            $name = $issue["node"]->name;
            $msg = "Issue with $name of new VCard: " . $issue["message"];

            if ($issue["level"] <= 2) { // warning
                Config::$logger->warning($msg);
            } else { // error
                Config::$logger->error($msg);
                $errors .= "$msg\n";
                $hasError = true;
            }
        }

        if ($hasError) {
            Config::$logger->debug($vcard->serialize());
            throw new \InvalidArgumentException($errors);
        }
    }

    /**
     * Provides the list of property names that should be requested upon call of refreshProperties().
     *
     * @return list<string> A list of property names including namespace prefix (e. g. '{DAV:}resourcetype').
     *
     * @see parent::getProperties()
     * @see parent::refreshProperties()
     */
    protected function getNeededCollectionPropertyNames(): array
    {
        $parentPropNames = parent::getNeededCollectionPropertyNames();
        $propNames = array_merge($parentPropNames, self::PROPNAMES);
        return array_values(array_unique($propNames));
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
