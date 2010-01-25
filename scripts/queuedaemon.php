#!/usr/bin/env php
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

define('INSTALLDIR', realpath(dirname(__FILE__) . '/..'));

$shortoptions = 'fi:at:';
$longoptions = array('id=', 'foreground', 'all', 'threads=', 'skip-xmpp', 'xmpp-only');

/**
 * Attempts to get a count of the processors available on the current system
 * to fan out multiple threads.
 *
 * Recognizes Linux and Mac OS X; others will return default of 1.
 *
 * @fixme move this to SpawningDaemon, but to get the default val for help
 *        text we seem to need it before loading infrastructure
 * @return intval
 */
function getProcessorCount()
{
    $cpus = 0;
    switch (PHP_OS) {
    case 'Linux':
        $cpuinfo = file('/proc/cpuinfo');
        foreach (file('/proc/cpuinfo') as $line) {
            if (preg_match('/^processor\s+:\s+(\d+)\s?$/', $line)) {
                $cpus++;
            }
        }
        break;
    case 'Darwin':
        $cpus = intval(shell_exec("/usr/sbin/sysctl -n hw.ncpu 2>/dev/null"));
        break;
    }
    if ($cpus) {
        return $cpus;
    }
    return 1;
}

$threads = getProcessorCount();
$helptext = <<<END_OF_QUEUE_HELP
Daemon script for running queued items.

    -i --id           Identity (default none)
    -f --foreground   Stay in the foreground (default background)
    -a --all          Handle queues for all local sites
                      (requires Stomp queue handler, status_network setup)
    -t --threads=<n>  Spawn <n> processing threads (default $threads)


END_OF_QUEUE_HELP;

require_once INSTALLDIR.'/scripts/commandline.inc';

require_once(INSTALLDIR.'/lib/daemon.php');
require_once(INSTALLDIR.'/classes/Queue_item.php');
require_once(INSTALLDIR.'/classes/Notice.php');

define('CLAIM_TIMEOUT', 1200);

/**
 * Queue handling daemon...
 *
 * The queue daemon by default launches in the background, at which point
 * it'll pass control to the configured QueueManager class to poll for updates.
 *
 * We can then pass individual items through the QueueHandler subclasses
 * they belong to.
 */
class QueueDaemon extends SpawningDaemon
{
    protected $allsites = false;

    function __construct($id=null, $daemonize=true, $threads=1, $allsites=false)
    {
        parent::__construct($id, $daemonize, $threads);
        $this->all = $allsites;
    }

    /**
     * Setup and start of run loop for this queue handler as a daemon.
     * Most of the heavy lifting is passed on to the QueueManager's service()
     * method, which passes control on to the QueueHandler's handle()
     * method for each item that comes in on the queue.
     *
     * @return boolean true on success, false on failure
     */
    function runThread()
    {
        $this->log(LOG_INFO, 'checking for queued notices');

        $master = new QueueMaster($this->get_id());
        $master->init($this->all);
        $master->service();

        $this->log(LOG_INFO, 'finished servicing the queue');

        $this->log(LOG_INFO, 'terminating normally');

        return true;
    }
}

class QueueMaster extends IoMaster
{
    /**
     * Initialize IoManagers for the currently configured site
     * which are appropriate to this instance.
     */
    function initManagers()
    {
        $classes = array();
        if (Event::handle('StartQueueDaemonIoManagers', array(&$classes))) {
            $classes[] = 'QueueManager';
        }
        Event::handle('EndQueueDaemonIoManagers', array(&$classes));

        foreach ($classes as $class) {
            $this->instantiate($class);
        }
    }
}

if (have_option('i')) {
    $id = get_option_value('i');
} else if (have_option('--id')) {
    $id = get_option_value('--id');
} else if (count($args) > 0) {
    $id = $args[0];
} else {
    $id = null;
}

if (have_option('t')) {
    $threads = intval(get_option_value('t'));
} else if (have_option('--threads')) {
    $threads = intval(get_option_value('--threads'));
} else {
    $threads = 0;
}
if (!$threads) {
    $threads = getProcessorCount();
}

$daemonize = !(have_option('f') || have_option('--foreground'));
$all = have_option('a') || have_option('--all');

if (have_option('--skip-xmpp')) {
    define('XMPP_EMERGENCY_FLAG', true);
}
if (have_option('--xmpp-only')) {
    define('XMPP_ONLY_FLAG', true);
}

$daemon = new QueueDaemon($id, $daemonize, $threads, $all);
$daemon->runOnce();

