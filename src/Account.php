<?php

/**
 * This is a simple class to represent credentials to a CardDAV Server.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;

class Account
{
    /********* PROPERTIES *********/

    /** @var string */
    private $username;

    /** @var string */
    private $password;

    /** @var string */
    private $baseUri;

    /********* PUBLIC FUNCTIONS *********/
    public function __construct(string $baseUri, string $username, string $password)
    {
        $this->baseUri  = $baseUri;
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Provides a CardDavClient object to interact with the server for this account.
     *
     * @param array $davClientOptions
     *  Default options for the CardDavClient object.
     *
     * @return CardDavClient
     *  A CardDavClient object to interact with the server for this account.
     */
    public function getClient(array $davClientOptions = [], string $baseUri = null): CardDavClient
    {
        $davClient = new CardDavClient($baseUri ?? $this->baseUri, $this->username, $this->password, $davClientOptions);
        return $davClient;
    }

    /**
     * Queries the given URI for the current-user-principal property.
     *
     * Property description by RFC5397: The DAV:current-user-principal property contains either a DAV:href or
     * DAV:unauthenticated XML element. The DAV:href element contains a URL to a principal resource corresponding to the
     * currently authenticated user. That URL MUST be one of the URLs in the DAV:principal-URL or DAV:alternate-URI-set
     * properties defined on the principal resource and MUST be an http(s) scheme URL. When authentication has not been
     * done or has failed, this property MUST contain the DAV:unauthenticated pseudo-principal.
     * In some cases, there may be multiple principal resources corresponding to the same authenticated principal. In
     * that case, the server is free to choose any one of the principal resource URIs for the value of the
     * DAV:current-user-principal property. However, servers SHOULD be consistent and use the same principal resource
     * URI for each authenticated principal.
     *
     * @param string $contextPathUri
     *  The given URI should typically be a context path per the terminology of RFC6764.
     *
     * @return
     *  The principal URI (string), or NULL in case of error. The returned URI is suited to be used for queries with
     *  this client (i.e. either a full URI, or meaningful as relative URI to the base URI of this client).
     */
    public function findCurrentUserPrincipal(string $contextPathUri): ?string
    {
        $client = $this->getClient();
        $result = $client->findProperties($contextPathUri, [XmlEN::CURUSRPRINC]);

        $princUrl = $result[0]["props"][XmlEN::CURUSRPRINC] ?? null;

        if (isset($princUrl)) {
            $princUrl = CardDavClient::concatUrl($result[0]["uri"], $princUrl);
            Config::$logger->info("principal URL: $princUrl");
        }

        return $princUrl;
    }

    /**
     * Queries the given URI for the current-user-principal property.
     *
     * Property description by RFC6352: The CARDDAV:addressbook-home-set property is meant to allow users to easily find
     * the address book collections owned by the principal. Typically, users will group all the address book collections
     * that they own under a common collection. This property specifies the URL of collections that are either address
     * book collections or ordinary collections that have child or descendant address book collections owned by the
     * principal.
     *
     * @param string $principalUri
     *  The given URI should be (one of) the authenticated user's principal URI(s).
     *
     * @return
     *  The user's addressbook home URI (string), or false in case of error. The returned URI is suited
     *  to be used for queries with this client (i.e. either a full URI,
     *  or meaningful as relative URI to the base URI of this client).
     */
    public function findAddressbookHome(string $principalUri): ?string
    {
        $client = $this->getClient();
        $result = $client->findProperties($principalUri, [XmlEN::ABOOK_HOME]);

        // FIXME per RFC several home locations could be returned, but we currently only use one. However, it is rather
        // unlikely that there would be several addressbook home locations.
        $addressbookHomeUri = $result[0]["props"][XmlEN::ABOOK_HOME][0] ?? null;

        if (isset($addressbookHomeUri)) {
            $addressbookHomeUri = CardDavClient::concatUrl($result[0]["uri"], $addressbookHomeUri);
            Config::$logger->info("addressbook home: $addressbookHomeUri");
        }

        return $addressbookHomeUri;
    }

    // RFC6352: An address book collection MUST report the DAV:collection and CARDDAV:addressbook XML elements in the
    // value of the DAV:resourcetype property.
    // CARDDAV:supported-address-data (supported Media Types (e.g. vCard3, vCard4) of an addressbook collection)
    // CARDDAV:addressbook-description (property of an addressbook collection)
    // CARDDAV:max-resource-size (maximum size in bytes for an address object of the addressbook collection)
    public function findAddressbooks(string $addressbookHomeUri): array
    {
        $client = $this->getClient();
        $abooks = $client->findProperties($addressbookHomeUri, [ XmlEN::RESTYPE ], "1");

        $abooksResult = [];
        foreach ($abooks as $abook) {
            if (in_array(XmlEN::RESTYPE_ABOOK, $abook["props"][XmlEN::RESTYPE])) {
                $abooksResult[] = $abook["uri"];
            }
        }

        return $abooksResult;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
