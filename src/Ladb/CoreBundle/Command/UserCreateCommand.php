<?php

namespace Ladb\CoreBundle\Command;

use Ladb\CoreBundle\Manager\Core\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * fos:user:create of FOSUserBundle (#24), with the same arguments and options.
 */
#[AsCommand(name: 'ladb:user:create', description: 'Create a user')]
class UserCreateCommand extends Command {

	private $userManager;

	public function __construct(UserManager $userManager) {
		$this->userManager = $userManager;
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->addArgument('username', InputArgument::REQUIRED, 'The username')
			->addArgument('email', InputArgument::REQUIRED, 'The email')
			->addArgument('password', InputArgument::REQUIRED, 'The password')
			->addOption('super-admin', null, InputOption::VALUE_NONE, 'Set the user as super admin')
			->addOption('inactive', null, InputOption::VALUE_NONE, 'Set the user as inactive')
			->setHelp(<<<EOT
The <info>ladb:user:create</info> command creates a user, and asks for the missing arguments:

  <info>php %command.full_name% matthieu matthieu@example.com mypassword</info>
EOT
		);
	}

	protected function interact(InputInterface $input, OutputInterface $output): void {
		$questions = array(
			'username' => 'Please choose a username:',
			'email'    => 'Please choose an email:',
			'password' => 'Please choose a password:',
		);
		foreach ($questions as $name => $text) {
			if ($input->getArgument($name)) {
				continue;
			}
			$question = new Question($text);
			$question->setValidator(function ($answer) use ($name) {
				if (null === $answer || '' === $answer) {
					throw new \RuntimeException(sprintf('The %s can not be empty.', $name));
				}
				return $answer;
			});
			$question->setHidden('password' === $name);
			$input->setArgument($name, $this->getHelper('question')->ask($input, $output, $question));
		}
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$username = $input->getArgument('username');

		$user = $this->userManager->createUser();
		$user->setUsername($username);
		$user->setEmail($input->getArgument('email'));
		$user->setPlainPassword($input->getArgument('password'));
		$user->setEnabled(!$input->getOption('inactive'));
		$user->setSuperAdmin($input->getOption('super-admin'));
		$this->userManager->updateUser($user);

		$output->writeln(sprintf('Created user <comment>%s</comment>', $username));

		return 0;
	}

}
