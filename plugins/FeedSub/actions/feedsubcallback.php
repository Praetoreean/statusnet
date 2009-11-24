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


class FeedSubCallbackAction extends Action
{
    function handle()
    {
        parent::handle();
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $this->handlePost();
        } else {
            $this->handleGet();
        }
    }
    
    /**
     * Handler for POST content updates from the hub
     */
    function handlePost()
    {
        $feedid = $this->arg('feed');
        common_log(LOG_INFO, "POST for feed id $feedid");
        if (!$feedid) {
            throw new ServerException('Empty or invalid feed id', 400);
        }

        $feedinfo = Feedinfo::staticGet('id', $feedid);
        if (!$feedinfo) {
            throw new ServerException('Unknown feed id ' . $feedid, 400);
        }
        
        $post = file_get_contents('php://input');
        $feedinfo->postUpdates($post);
    }
    
    /**
     * Handler for GET verification requests from the hub
     */
    function handleGet()
    {
        $mode = $this->arg('hub_mode');
        $topic = $this->arg('hub_topic');
        $challenge = $this->arg('hub_challenge');
        $lease_seconds = $this->arg('hub_lease_seconds');
        $verify_token = $this->arg('hub_verify_token');
        
        if ($mode != 'subscribe' && $mode != 'unsubscribe') {
            common_log(LOG_WARNING, __METHOD__ . ": bogus hub callback with mode \"$mode\"");
            throw new ServerException("Bogus hub callback: bad mode", 404);
        }
        
        $feedinfo = Feedinfo::staticGet('feeduri', $topic);
        if (!$feedinfo) {
            common_log(LOG_WARNING, __METHOD__ . ": bogus hub callback for unknown feed $topic");
            throw new ServerException("Bogus hub callback: unknown feed", 404);
        }

        # Can't currently set the token in our sub api
        #if ($feedinfo->verify_token !== $verify_token) {
        #    common_log(LOG_WARNING, __METHOD__ . ": bogus hub callback with bad token \"$verify_token\" for feed $topic");
        #    throw new ServerError("Bogus hub callback: bad token", 404);
        #}
        
        // OK!
        common_log(LOG_INFO, __METHOD__ . ': sub confirmed');
        $feedinfo->sub_start = common_sql_date(time());
        if ($lease_seconds > 0) {
            $feedinfo->sub_end = common_sql_date(time() + $lease_seconds);
        } else {
            $feedinfo->sub_end = null;
        }
        $feedinfo->update();
        
        print $challenge;
    }
}
