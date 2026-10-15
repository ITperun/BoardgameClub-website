<?php

declare(strict_types=1);

namespace App\Model;

use Nette\Application\UI\TemplateFactory;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;
use Nette\Mail\Message;
use Nette\Mail\Mailer;

class MailService
{
	public function __construct(
		private Mailer $mailer,
		private TemplateFactory $templateFactory
	) {
	}

	/**
	 * Уведомление о регистрации (пользователю и копия админу)
	 */
	public function sendRegistrationEmail(string $userEmail, string $username, string $adminEmail): void
	{
		/** @var DefaultTemplate $template */
		$template = $this->templateFactory->createTemplate();
		$template->setFile(__DIR__ . '/../Presentation/Email/registration.latte');
		$template->setParameters([
			'username' => $username,
		]);

		$mail = new Message;
		$mail->setFrom('Your@seznam.cz', 'Klub Desktopovych Her')
			->addTo($userEmail)
			->addBcc($adminEmail)
			->setSubject('Vítejte v MOP - Potvrzení registrace')
			->setHtmlBody($template->renderToString());

		$this->mailer->send($mail);
	}

	/**
	 * Уведомление администратора о новом событии, требующем модерации
	 */
	public function sendNewEventRequestNotification(string $adminEmail, string $eventTitle, string $organizerName): void
	{
		/** @var DefaultTemplate $template */
		$template = $this->templateFactory->createTemplate();
		$template->setFile(__DIR__ . '/../Presentation/Email/newEventRequest.latte');
		$template->setParameters([
			'eventTitle' => $eventTitle,
			'organizerName' => $organizerName,
		]);

		$mail = new Message;
		$mail->setFrom('Your@seznam.cz', 'Klub Desktopovych Her')
			->addTo($adminEmail)
			->setSubject('Nová žádost o schválení události: ' . $eventTitle)
			->setHtmlBody($template->renderToString());

		$this->mailer->send($mail);
	}

	/**
	 * Уведомление о результате модерации
	 */
	public function sendModerationEmail(
		string $recipientEmail,
		string $userName,
		string $eventTitle,
		string $status
	): void {
		/** @var DefaultTemplate $template */
		$template = $this->templateFactory->createTemplate();
		$template->setFile(__DIR__ . '/../Presentation/Email/moderationResult.latte');
		$template->setParameters([
			'name' => $userName,
			'eventTitle' => $eventTitle,
			'status' => $status,
		]);

		$mail = new Message;
		$mail->setFrom('Your@seznam.cz', 'Klub Desktopovych Her')
			->addTo($recipientEmail)
			->setSubject('Výsledek moderace vaší události: ' . $eventTitle)
			->setHtmlBody($template->renderToString());

		$this->mailer->send($mail);
	}

	/**
	 * Уведомление администратора о новой регистрации пользователя
	 */
	public function sendNewUserRegistrationNotification(
		string $adminEmail,
		string $username,
		string $userEmail,
		string $firstname,
		string $lastname
	): void {
		/** @var DefaultTemplate $template */
		$template = $this->templateFactory->createTemplate();
		$template->setFile(__DIR__ . '/../Presentation/Email/newUserRegistration.latte');
		$template->setParameters([
			'username' => $username,
			'userEmail' => $userEmail,
			'firstname' => $firstname,
			'lastname' => $lastname,
			'registrationDate' => (new \DateTime())->format('d.m.Y H:i:s'),
		]);

		$mail = new Message;
		$mail->setFrom('Your@seznam.cz', 'Klub Desktopovych Her')
			->addTo($adminEmail)
			->setSubject('Nová registrace uživatele: ' . $username)
			->setHtmlBody($template->renderToString());

		$this->mailer->send($mail);
	}
}
