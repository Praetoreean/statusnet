<?php
/**
 * StatusNet, the distributed open-source microblogging tool
 *
 * Default settings for core configuration
 *
 * PHP version 5
 *
 * LICENCE: This program is free software: you can redistribute it and/or modify
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
 *
 * @category  Config
 * @package   StatusNet
 * @author    Evan Prodromou <evan@status.net>
 * @copyright 2008-9 StatusNet, Inc.
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://status.net/
 */

$default =
  array('site' =>
        array('name' => 'Just another StatusNet microblog',
              'server' => $_server,
              'theme' => 'default',
              'path' => $_path,
              'logfile' => null,
              'logo' => null,
              'logdebug' => false,
              'fancy' => false,
              'locale_path' => INSTALLDIR.'/locale',
              'language' => 'en_US',
              'languages' => get_all_languages(),
              'email' =>
              array_key_exists('SERVER_ADMIN', $_SERVER) ? $_SERVER['SERVER_ADMIN'] : null,
              'broughtby' => null,
              'timezone' => 'UTC',
              'broughtbyurl' => null,
              'closed' => false,
              'inviteonly' => false,
              'private' => false,
              'ssl' => 'never',
              'sslserver' => null,
              'shorturllength' => 30,
              'dupelimit' => 60, # default for same person saying the same thing
              'textlimit' => 140,
              ),
        'db' =>
        array('database' => 'YOU HAVE TO SET THIS IN config.php',
              'schema_location' => INSTALLDIR . '/classes',
              'class_location' => INSTALLDIR . '/classes',
              'require_prefix' => 'classes/',
              'class_prefix' => '',
              'mirror' => null,
              'utf8' => true,
              'db_driver' => 'DB', # XXX: JanRain libs only work with DB
              'quote_identifiers' => false,
              'type' => 'mysql',
              'schemacheck' => 'runtime'), // 'runtime' or 'script'
        'syslog' =>
        array('appname' => 'statusnet', # for syslog
              'priority' => 'debug', # XXX: currently ignored
              'facility' => LOG_USER),
        'queue' =>
        array('enabled' => false,
              'subsystem' => 'db', # default to database, or 'stomp'
              'stomp_server' => null,
              'queue_basename' => 'statusnet',
              'stomp_username' => null,
              'stomp_password' => null,
              ),
        'license' =>
        array('url' => 'http://creativecommons.org/licenses/by/3.0/',
              'title' => 'Creative Commons Attribution 3.0',
              'image' => 'http://i.creativecommons.org/l/by/3.0/80x15.png'),
        'mail' =>
        array('backend' => 'mail',
              'params' => null,
              'domain_check' => true),
        'nickname' =>
        array('blacklist' => array(),
              'featured' => array()),
        'profile' =>
        array('banned' => array(),
              'biolimit' => null),
        'avatar' =>
        array('server' => null,
              'dir' => INSTALLDIR . '/avatar/',
              'path' => $_path . '/avatar/'),
        'background' =>
        array('server' => null,
              'dir' => INSTALLDIR . '/background/',
              'path' => $_path . '/background/'),
        'public' =>
        array('localonly' => true,
              'blacklist' => array(),
              'autosource' => array()),
        'theme' =>
        array('server' => null,
              'dir' => null,
              'path'=> null),
        'throttle' =>
        array('enabled' => false, // whether to throttle edits; false by default
              'count' => 20, // number of allowed messages in timespan
              'timespan' => 600), // timespan for throttling
        'xmpp' =>
        array('enabled' => false,
              'server' => 'INVALID SERVER',
              'port' => 5222,
              'user' => 'update',
              'encryption' => true,
              'resource' => 'uniquename',
              'password' => 'blahblahblah',
              'host' => null, # only set if != server
              'debug' => false, # print extra debug info
              'public' => array()), # JIDs of users who want to receive the public stream
        'invite' =>
        array('enabled' => true),
        'tag' =>
        array('dropoff' => 864000.0),
        'popular' =>
        array('dropoff' => 864000.0),
        'daemon' =>
        array('piddir' => '/var/run',
              'user' => false,
              'group' => false),
        'emailpost' =>
        array('enabled' => true),
        'sms' =>
        array('enabled' => true),
        'twitterimport' =>
        array('enabled' => false),
        'integration' =>
        array('source' => 'StatusNet', # source attribute for Twitter
              'taguri' => $_server.',2009'), # base for tag URIs
        'twitter' =>
        array('enabled'       => true,
              'consumer_key'    => null,
              'consumer_secret' => null),
        'memcached' =>
        array('enabled' => false,
              'server' => 'localhost',
              'base' => null,
              'port' => 11211),
        'ping' =>
        array('notify' => array()),
        'inboxes' =>
        array('enabled' => true), # ignored after 0.9.x
        'newuser' =>
        array('default' => null,
              'welcome' => null),
        'snapshot' =>
        array('run' => 'web',
              'frequency' => 10000,
              'reporturl' => 'http://status.net/stats/report'),
        'attachments' =>
        array('server' => null,
              'dir' => INSTALLDIR . '/file/',
              'path' => $_path . '/file/',
              'supported' => array('image/png',
                                   'image/jpeg',
                                   'image/gif',
                                   'image/svg+xml',
                                   'audio/mpeg',
                                   'audio/x-speex',
                                   'application/ogg',
                                   'application/pdf',
                                   'application/vnd.oasis.opendocument.text',
                                   'application/vnd.oasis.opendocument.text-template',
                                   'application/vnd.oasis.opendocument.graphics',
                                   'application/vnd.oasis.opendocument.graphics-template',
                                   'application/vnd.oasis.opendocument.presentation',
                                   'application/vnd.oasis.opendocument.presentation-template',
                                   'application/vnd.oasis.opendocument.spreadsheet',
                                   'application/vnd.oasis.opendocument.spreadsheet-template',
                                   'application/vnd.oasis.opendocument.chart',
                                   'application/vnd.oasis.opendocument.chart-template',
                                   'application/vnd.oasis.opendocument.image',
                                   'application/vnd.oasis.opendocument.image-template',
                                   'application/vnd.oasis.opendocument.formula',
                                   'application/vnd.oasis.opendocument.formula-template',
                                   'application/vnd.oasis.opendocument.text-master',
                                   'application/vnd.oasis.opendocument.text-web',
                                   'application/x-zip',
                                   'application/zip',
                                   'text/plain',
                                   'video/mpeg',
                                   'video/mp4',
                                   'video/quicktime',
                                   'video/mpeg'),
              'file_quota' => 5000000,
              'user_quota' => 50000000,
              'monthly_quota' => 15000000,
              'uploads' => true,
              'filecommand' => '/usr/bin/file',
              ),
        'group' =>
        array('maxaliases' => 3,
              'desclimit' => null),
        'oohembed' => array('endpoint' => 'http://oohembed.com/oohembed/'),
        'search' =>
        array('type' => 'fulltext'),
        'sessions' =>
        array('handle' => false, // whether to handle sessions ourselves
              'debug' => false), // debugging output for sessions
        'design' =>
        array('backgroundcolor' => null, // null -> 'use theme default'
              'contentcolor' => null,
              'sidebarcolor' => null,
              'textcolor' => null,
              'linkcolor' => null,
              'backgroundimage' => null,
              'disposition' => null),
        'notice' =>
        array('contentlimit' => null),
        'message' =>
        array('contentlimit' => null),
        'location' =>
        array('namespace' => 1), // 1 = geonames, 2 = Yahoo Where on Earth
        'omb' =>
        array('timeout' => 5), // HTTP request timeout in seconds when contacting remote hosts for OMB updates
        );
