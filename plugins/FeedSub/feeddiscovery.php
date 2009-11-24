<?php
/*
 * StatusNet - the distributed open-source microblogging tool
 * Copyright (C) 2009, StatusNet, Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * @package FeedSubPlugin
 * @maintainer Brion Vibber <brion@status.net>
 */

if (!defined('STATUSNET') && !defined('LACONICA')) { exit(1); }

class FeedSubBadURLException extends FeedSubException
{
}

class FeedSubBadResponseException extends FeedSubException
{
}

class FeedSubEmptyException extends FeedSubException
{
}

class FeedSubBadHTMLException extends FeedSubException
{
}

class FeedSubUnrecognizedTypeException extends FeedSubException
{
}

class FeedSubNoFeedException extends FeedSubException
{
}

class FeedDiscovery
{
    public $uri;
    public $type;
    public $body;


    public function feedMunger()
    {
        require_once 'XML/Feed/Parser.php';
        $feed = new XML_Feed_Parser($this->body, false, false, true); // @fixme
        return new FeedMunger($feed, $this->uri);
    }

    /**
     * @param string $url
     * @param bool $htmlOk
     * @return string with validated URL
     * @throws FeedSubBadURLException
     * @throws FeedSubBadHtmlException
     * @throws FeedSubNoFeedException
     * @throws FeedSubEmptyException
     * @throws FeedSubUnrecognizedTypeException
     */
    function discoverFromURL($url, $htmlOk=true)
    {
        try {
            $client = new HTTPClient();
            $response = $client->get($url);
        } catch (HTTP_Request2_Exception $e) {
            throw new FeedSubBadURLException($e);
        }

        if ($htmlOk) {
            $type = $response->getHeader('Content-Type');
            $isHtml = preg_match('!^(text/html|application/xhtml\+xml)!i', $type);
            if ($isHtml) {
                $target = $this->discoverFromHTML($response->getUrl(), $response->getBody());
                if (!$target) {
                    throw new FeedSubNoFeedException($url);
                }
                return $this->discoverFromURL($target, false);
            }
        }
        
        return $this->initFromResponse($response);
    }
    
    function initFromResponse($response)
    {
        if (!$response->isOk()) {
            throw new FeedSubBadResponseException($response->getCode());
        }

        $sourceurl = $response->getUrl();
        $body = $response->getBody();
        if (!$body) {
            throw new FeedSubEmptyException($sourceurl);
        }

        $type = $response->getHeader('Content-Type');
        if (preg_match('!^(text/xml|application/xml|application/(rss|atom)\+xml)!i', $type)) {
            $this->uri = $sourceurl;
            $this->type = $type;
            $this->body = $body;
            return true;
        } else {
            common_log(LOG_WARNING, "Unrecognized feed type $type for $sourceurl");
            throw new FeedSubUnrecognizedTypeException($type);
        }
    }

    /**
     * @param string $url source URL, used to resolve relative links
     * @param string $body HTML body text
     * @return mixed string with URL or false if no target found
     */
    function discoverFromHTML($url, $body)
    {
        // DOMDocument::loadHTML may throw warnings on unrecognized elements.
        $old = error_reporting(error_reporting() & ~E_WARNING);
        $dom = new DOMDocument();
        $ok = $dom->loadHTML($body);
        error_reporting($old);

        if (!$ok) {
            throw new FeedSubBadHtmlException();
        }

        // Autodiscovery links may be relative to the page's URL or <base href>
        $base = false;
        $nodes = $dom->getElementsByTagName('base');
        for ($i = 0; $i < $nodes->length; $i++) {
            $node = $nodes->item($i);
            if ($node->hasAttributes()) {
                $href = $node->attributes->getNamedItem('href');
                if ($href) {
                    $base = trim($href->value);
                }
            }
        }
        if ($base) {
            $base = $this->resolveURI($base, $url);
        } else {
            $base = $url;
        }

        // Ok... now on to the links!
        // @fixme merge with the munger link checks
        $nodes = $dom->getElementsByTagName('link');
        for ($i = 0; $i < $nodes->length; $i++) {
            $node = $nodes->item($i);
            if ($node->hasAttributes()) {
                $rel = $node->attributes->getNamedItem('rel');
                $type = $node->attributes->getNamedItem('type');
                $href = $node->attributes->getNamedItem('href');
                if ($rel && $type && $href) {
                    $rel = trim($rel->value);
                    $type = trim($type->value);
                    $href = trim($href->value);

                    $feedTypes = array(
                        'application/rss+xml',
                        'application/atom+xml',
                    );
                    if (trim($rel) == 'alternate' && in_array($type, $feedTypes)) {
                        return $this->resolveURI($href, $base);
                    }
                }
            }
        }

        return false;
    }

    /**
     * Resolve a possibly relative URL against some absolute base URL
     * @param string $rel relative or absolute URL
     * @param string $base absolute URL
     * @return string absolute URL, or original URL if could not be resolved.
     */
    function resolveURI($rel, $base)
    {
        require_once "Net/URL2.php";
        try {
            $relUrl = new Net_URL2($rel);
            if ($relUrl->isAbsolute()) {
                return $rel;
            }
            $baseUrl = new Net_URL2($base);
            $absUrl = $baseUrl->resolve($relUrl);
            return $absUrl->getURL();
        } catch (Exception $e) {
            common_log(LOG_WARNING, 'Unable to resolve relative link "' .
                $rel . '" against base "' . $base . '": ' . $e->getMessage());
            return $rel;
        }
    }
}
