<?php
namespace Sitegeist\MagicWand\Command;

/*                                                                        *
 * This script belongs to the Neos Flow package "Sitegeist.MagicWand".    *
 *                                                                        *
 *                                                                        */

use Neos\Flow\Annotations as Flow;
use Neos\Utility\Files as FileUtils;
use Neos\Flow\Core\Bootstrap;
use Sitegeist\MagicWand\Status\Service as StatusService;

/**
 * @Flow\Scope("singleton")
 */
class StashCommandController extends AbstractCommandController
{
    /**
     * @Flow\Inject
     * @var StatusService
     */
    protected $statusService;

    /**
     * @Flow\InjectConfiguration(path="pathToMetadata")
     * @var string
     */
    protected $pathToMetadata;

    /**
     * Show the current stash status
     *
     * @param string $dateFormat
     * @return void
     */
    public function statusCommand($dateFormat = 'g:ia \o\n l jS F Y')
    {
        $manifest = $this->statusService->getCurrentManifest();
        $name = $manifest->get('stash', 'name');

        if (!$name) {
            $this->outputLine('No current stash data found.');
        } else  {
            $this->outputLine();
            $this->outputLine('Current Stash Entry: <b>%s</b>', [$name]);
            $this->outputLine();

            $this->outputLine('Most recently restored at %s', [$manifest->get('stash', 'latest')->format($dateFormat)]);
        }
    }

    /**
     * Creates a new stash entry with the given name.
     *
     * @param string $name The name for the new stash entry
     * @return void
     */
    public function createCommand($name)
    {
        $startTimestamp = time();

        #######################
        #     Build Paths     #
        #######################

        $basePath = sprintf(
            FLOW_PATH_ROOT . 'Data/MagicWandStash/%s',
            $name
        );

        $databaseDestination = $basePath . '/database.sql';
        $persistentDestination = $basePath . '/persistent/';
        $metaDataDestination = $basePath . '/.magicwand/';

        FileUtils::createDirectoryRecursively($basePath);

        #######################
        # Check Configuration #
        #######################

        $this->checkConfiguration();

        ##################
        # Define Secrets #
        ##################

        $this->addSecret($this->databaseConfiguration['user']);
        $this->addSecret($this->databaseConfiguration['password']);

        ######################
        #  Backup Database   #
        ######################

        $this->outputHeadLine('Backup Database');
        $this->executeLocalShellCommand(
            'mysqldump --add-drop-table --host="%s" --user="%s" --password="%s" %s > %s',
            [
                $this->databaseConfiguration['host'],
                $this->databaseConfiguration['user'],
                $this->databaseConfiguration['password'],
                $this->databaseConfiguration['dbname'],
                $databaseDestination
            ]
        );

        ###############################
        # Backup Persistent Resources #
        ###############################

        $this->outputHeadLine('Backup Persistent Resources');
        $this->executeLocalShellCommand(
            'cp -al %s %s',
            [
                FLOW_PATH_ROOT . 'Data/Persistent',
                $persistentDestination
            ]
        );

        ##############################
        # Backup MagicWand Meta Data #
        ##############################

        $this->outputHeadLine('Backup MagicWand Meta Data');
        $this->executeLocalShellCommand(
            'cp -al %s %s',
            [
                $this->pathToMetadata,
                $metaDataDestination
            ]
        );

        #################
        # Final Message #
        #################

        $endTimestamp = time();
        $duration = $endTimestamp - $startTimestamp;

        $this->outputHeadLine('Done');
        $this->outputLine('Successfuly stashed %s in %s seconds', [$name, $duration]);
    }

    /**
     * Lists all entries
     *
     * @return void
     */
    public function listCommand()
    {
        $basePath = sprintf(FLOW_PATH_ROOT . 'Data/MagicWandStash');

        if (!is_dir($basePath)) {
            $this->outputLine('Stash is empty.');
            $this->quit(1);
        }

        $baseDir = new \DirectoryIterator($basePath);
        $anyEntry = false;

        foreach ($baseDir as $entry) {
            if (!in_array($entry, ['.', '..'])) {
                $this->outputLine(' • %s', [$entry->getFilename()]);
                $anyEntry = true;
            }
        }

        if (!$anyEntry) {
            $this->outputLine('Stash is empty.');
            $this->quit(1);
        }
    }

    /**
     * Clear the whole stash
     *
     * @return void
     */
    public function clearCommand()
    {
        $startTimestamp = time();

        $path = FLOW_PATH_ROOT . 'Data/MagicWandStash';
        FileUtils::removeDirectoryRecursively($path);

        #################
        # Final Message #
        #################

        $endTimestamp = time();
        $duration = $endTimestamp - $startTimestamp;

        $this->outputHeadLine('Done');
        $this->outputLine('Cleanup successful in %s seconds', [$duration]);
    }

    /**
     * Restores stash entries
     *
     * @param string $name The name of the stash entry that will be restored
     * @param boolean $yes confirm execution without further input
     * @param boolean $keepDb skip dropping of database during sync
     * @return void
     */
    public function restoreCommand($name, $yes = false, $keepDb = false)
    {
        $basePath = sprintf(FLOW_PATH_ROOT . 'Data/MagicWandStash/%s', $name);
        $this->restoreStashEntry($basePath, $name, $yes, true, $keepDb);
    }

    /**
     * Remove a named stash entry
     *
     * @param string $name The name of the stash entry that will be removed
     * @param boolean $yes confirm execution without further input
     * @return void
     */
    public function removeCommand($name, $yes = false)
    {
        $directory = FLOW_PATH_ROOT . 'Data/MagicWandStash/' . $name;

        if (!is_dir($directory)) {
            $this->outputLine('<error>%s does not exist</error>', [$name]);
            $this->quit(1);
        }

        if (!$yes) {
            $this->outputLine("Are you sure you want to do this?  Type 'yes' to continue: ");
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);

            if (trim($line) != 'yes') {
                $this->outputLine('exit');
                $this->quit(1);
            } else {
                $this->outputLine();
                $this->outputLine();
            }
        }

        ###############
        # Start Timer #
        ###############


        $startTimestamp = time();

        FileUtils::removeDirectoryRecursively($directory);

        #################
        # Final Message #
        #################

        $endTimestamp = time();
        $duration = $endTimestamp - $startTimestamp;

        $this->outputHeadLine('Done');
        $this->outputLine('Cleanup removed stash %s in %s seconds', [$name, $duration]);
    }

    /**
     * Actual restore logic
     *
     * @param string $source
     * @param string $name
     * @param boolean $force
     * @param boolean $keepDb
     * @return void
     */
    protected function restoreStashEntry($source, $name, $force = false, $preserve = true, $keepDb = false)
    {
        if (!is_dir($source)) {
            $this->outputLine('<error>%s does not exist</error>', [$name]);
            $this->quit(1);
        }

        #################
        # Are you sure? #
        #################

        if (!$force) {
            $this->outputLine("Are you sure you want to do this?  Type 'yes' to continue: ");
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);

            if (trim($line) != 'yes') {
                $this->outputLine('exit');
                $this->quit(1);
            } else {
                $this->outputLine();
                $this->outputLine();
            }
        }

        ######################
        # Measure Start Time #
        ######################

        $startTimestamp = time();

        #######################
        # Check Configuration #
        #######################

        $this->checkConfiguration();

        ##################
        # Define Secrets #
        ##################

        $this->addSecret($this->databaseConfiguration['user']);
        $this->addSecret($this->databaseConfiguration['password']);

        ########################
        # Drop and Recreate DB #
        ########################

        if ($keepDb == false) {
            $this->outputHeadLine('Drop and Recreate DB');

            $emptyLocalDbSql = 'DROP DATABASE `'
                . $this->databaseConfiguration['dbname']
                . '`; CREATE DATABASE `'
                . $this->databaseConfiguration['dbname']
                . '` collate utf8_unicode_ci;';

            $this->executeLocalShellCommand(
                'echo %s | mysql --host=%s --user=%s --password=%s',
                [
                    escapeshellarg($emptyLocalDbSql),
                    $this->databaseConfiguration['host'],
                    $this->databaseConfiguration['user'],
                    $this->databaseConfiguration['password']
                ]
            );
        } else {
            $this->outputHeadLine('Skipped (Drop and Recreate DB)');
        }

        ######################
        #  Restore Database  #
        ######################

        $this->outputHeadLine('Restore Database');
        $this->executeLocalShellCommand(
            'mysql  --host="%s" --user="%s" --password="%s" %s < %s',
            [
                $this->databaseConfiguration['host'],
                $this->databaseConfiguration['user'],
                $this->databaseConfiguration['password'],
                $this->databaseConfiguration['dbname'],
                $source . '/database.sql'
            ]
        );

        ################################
        # Restore Persistent Resources #
        ################################

        $this->outputHeadLine('Restore Persistent Resources');
        $this->executeLocalShellCommand(
            'rm -rf %s && cp -al %s %1$s',
            [
                FLOW_PATH_ROOT . 'Data/Persistent',
                $source . '/persistent'
            ]
        );


        if (!$preserve) {
            FileUtils::removeDirectoryRecursively($source);
        }

        ################
        # Clear Caches #
        ################

        $this->outputHeadLine('Clear Caches');
        $this->executeLocalFlowCommand('flow:cache:flush');


        ##############
        # Migrate DB #
        ##############

        $this->outputHeadLine('Migrate DB');
        $this->executeLocalFlowCommand('doctrine:migrate');

        #####################
        # Publish Resources #
        #####################

        $this->outputHeadLine('Publish Resources');
        $this->executeLocalFlowCommand('resource:publish');

        #################
        # Final Message #
        #################

        $endTimestamp = time();
        $duration = $endTimestamp - $startTimestamp;

        $this->outputHeadLine('Done');
        $this->outputLine('Successfuly restored %s in %s seconds', [$name, $duration]);
    }

    /**
     * @throws \Neos\Flow\Mvc\Exception\StopActionException
     */
    protected function checkConfiguration()
    {
        $this->outputHeadLine('Check Configuration');

        if ($this->databaseConfiguration['driver'] !== 'pdo_mysql') {
            $this->outputLine(' only mysql is supported');
            $this->quit(1);
        }

        $this->outputLine(' - Configuration seems ok ...');
    }
}
