<?php

namespace Ladb\CoreBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'ladb:backup:dumpdb', description: 'Dump the database')]
class BackupDumpdbCommand extends Command {

	private $databaseHost;
	private $databaseName;
	private $databasePassword;
	private $databasePort;
	private $databaseUser;

	public function __construct(
		$databaseHost,
		$databaseName,
		$databasePassword,
		$databasePort,
		$databaseUser
	) {
		$this->databaseHost = $databaseHost;
		$this->databaseName = $databaseName;
		$this->databasePassword = $databasePassword;
		$this->databasePort = $databasePort;
		$this->databaseUser = $databaseUser;
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->addOption('dump-dir', null, InputOption::VALUE_REQUIRED, 'Define the dump dir', 'backups')
			->addOption('compress', null, InputOption::VALUE_NONE, 'Define if dump file is compressed')
			->addOption('overwrite', null, InputOption::VALUE_NONE, 'Define previous dump file is overwrited')
			->addOption('table', null, InputOption::VALUE_REQUIRED, 'Dump this table ONLY')
			->addOption('ignore-table', null, InputOption::VALUE_REQUIRED, 'Ignore given table (write without DB name)')
			->addOption('where', null, InputOption::VALUE_REQUIRED, 'Dump only selected records. Quotes are mandatory.')
			->setHelp(<<<EOT
The <info>ladb:backup:db</info> command dump the database
EOT
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {

		$dumpDir = $input->getOption('dump-dir');
		$compress = $input->getOption('compress');
		$overwrite = $input->getOption('overwrite');
		$table = $input->getOption('table');
		$ignoreTable = $input->getOption('ignore-table');
		$where = $input->getOption('where');

		$dbHost = $this->databaseHost;
		$dbPort = $this->databasePort;
		$dbUser = $this->databaseUser;
		$dbPassword = $this->databasePassword;
		$dbName = $this->databaseName;
		$sqlFile = date('Ymd_His').'.'.$dbName.(!is_null($table) ? '.'.$table : '').'.sql';
		$fs = new Filesystem();

		// Compute the mysqldump command

		$mysqldumpCommand = 'mysqldump';

		if (!is_null($dbUser)) {
			$mysqldumpCommand .= ' --user='.$dbUser;
		}
		if (!is_null($dbPassword)) {
			$mysqldumpCommand .= ' --password='.$dbPassword;
		}
		if (!is_null($dbHost)) {
			$mysqldumpCommand .= ' --host='.$dbHost;
		}
		if (!is_null($dbPort)) {
			$mysqldumpCommand .= ' --port='.$dbPort;
		}
		if (!is_null($ignoreTable)) {
			$mysqldumpCommand .= ' --ignore-table='.$dbName.'.'.$ignoreTable;
		}
		if (!is_null($where)) {
			$mysqldumpCommand .= ' --where="'.$where.'"';
		}
		if (!is_null($dbName)) {
			$mysqldumpCommand .= ' '.$dbName;
		}
		if (!is_null($table)) {
			$mysqldumpCommand .= ' '.$table;
		}
		if (!empty($dumpDir)) {

			$sqlFile = $dumpDir.'/'.$sqlFile;

			// Create dumpDir if it does not exist
			if (!$fs->exists($dumpDir)) {
				$fs->mkdir($dumpDir);
			}

		}

		// Remove previous dump
		if ($fs->exists($sqlFile)) {
			if ($overwrite) {
				$fs->remove($sqlFile);
			} else {
				$output->writeln('<error>A previous dump already exists. Use --overwrite to overwrite it.</error>');
				return 0;
			}
		}

		// Execute mysqldump command
		$mysqldumpCommand .= ' > '.$sqlFile;
		$output->writeln('<info>Executing mysqldump command ... </info>');
		$output->writeln(' '.$mysqldumpCommand);
		if (system($mysqldumpCommand) === false) {
			$output->writeln('<error>Error executing mysqldump command.</error>');
			return 0;
		}

		if ($compress && $fs->exists($sqlFile)) {

			$gzFile = $sqlFile.'.gz';

			if ($fs->exists($gzFile)) {
				$fs->remove($gzFile);
			}

			// Execute gzip command
			$tarCommand = 'gzip < '.$sqlFile.' > '.$gzFile;
			if (system($tarCommand) === false) {
				$output->writeln('<error>Error executing gzip command.</error>');
				return 0;
			}

		}

		$output->writeln('<info>Dump complete at </info>'.$sqlFile.($compress ? '<info> and </info>'.$gzFile : ''));

		return 0;

	}

}