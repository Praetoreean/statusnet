<?php
/*
 * StatusNet - the distributed open-source microblogging tool
 * Copyright (C) 2008, 2009, StatusNet, Inc.
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

if (!defined('STATUSNET') && !defined('LACONICA')) { exit(1); }

/**
 * Base class for queue handlers.
 *
 * As extensions of the Daemon class, each queue handler has the ability
 * to launch itself in the background, at which point it'll pass control
 * to the configured QueueManager class to poll for updates.
 *
 * Subclasses must override at least the following methods:
 * - transport
 * - handle_notice
 */
#class QueueHandler extends Daemon
class QueueHandler
{

#    function __construct($id=null, $daemonize=true)
#    {
#        parent::__construct($daemonize);
#
#        if ($id) {
#            $this->set_id($id);
#        }
#    }

    /**
     * How many seconds a polling-based queue manager should wait between
     * checks for new items to handle.
     *
     * Defaults to 60 seconds; override to speed up or slow down.
     *
     * @fixme not really compatible with global queue manager
     * @return int timeout in seconds
     */
#    function timeout()
#    {
#        return 60;
#    }

#    function class_name()
#    {
#        return ucfirst($this->transport()) . 'Handler';
#    }

#    function name()
#    {
#        return strtolower($this->class_name().'.'.$this->get_id());
#    }

    /**
     * Return transport keyword which identifies items this queue handler
     * services; must be defined for all subclasses.
     *
     * Must be 8 characters or less to fit in the queue_item database.
     * ex "email", "jabber", "sms", "irc", ...
     *
     * @return string
     */
    function transport()
    {
        return null;
    }

    /**
     * Here's the meat of your queue handler -- you're handed a Notice
     * object, which you may do as you will with.
     *
     * If this function indicates failure, a warning will be logged
     * and the item is placed back in the queue to be re-run.
     *
     * @param Notice $notice
     * @return boolean true on success, false on failure
     */
    function handle_notice($notice)
    {
        return true;
    }

    /**
     * Setup and start of run loop for this queue handler as a daemon.
     * Most of the heavy lifting is passed on to the QueueManager's service()
     * method, which passes control back to our handle_notice() method for
     * each notice that comes in on the queue.
     *
     * Most of the time this won't need to be overridden in a subclass.
     *
     * @return boolean true on success, false on failure
     */
    function run()
    {
        if (!$this->start()) {
            $this->log(LOG_WARNING, 'failed to start');
            return false;
        }

        $this->log(LOG_INFO, 'checking for queued notices');

        $queue   = $this->transport();
        $timeout = $this->timeout();

        $qm = QueueManager::get();

        $qm->service($queue, $this);

        $this->log(LOG_INFO, 'finished servicing the queue');

        if (!$this->finish()) {
            $this->log(LOG_WARNING, 'failed to clean up');
            return false;
        }

        $this->log(LOG_INFO, 'terminating normally');

        return true;
    }


    function log($level, $msg)
    {
        common_log($level, $this->class_name() . ' ('. $this->get_id() .'): '.$msg);
    }
}

