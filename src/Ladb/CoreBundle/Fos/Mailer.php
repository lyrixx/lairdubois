<?php

namespace Ladb\CoreBundle\Fos;

use FOS\UserBundle\Mailer\TwigSwiftMailer;

/**
 * The mails of FOSUserBundle (forgotten password) as its default mailer (FOS\UserBundle\Mailer\Mailer)
 * sends them, without the templating component it needs: the whole template rendered, its first line
 * as subject and the rest as body. TwigSwiftMailer renders the blocks of the template one by one, and
 * would end the body with the line breaks that the default mailer trims.
 */
final class Mailer extends TwigSwiftMailer {

	protected function sendMessage($templateName, $context, $fromEmail, $toEmail) {
		$renderedLines = explode("\n", trim($this->twig->render($templateName, $context)));
		$subject = array_shift($renderedLines);
		$body = implode("\n", $renderedLines);

		$message = (new \Swift_Message())
			->setSubject($subject)
			->setFrom($fromEmail)
			->setTo($toEmail)
			->setBody($body);

		$this->mailer->send($message);
	}

}
