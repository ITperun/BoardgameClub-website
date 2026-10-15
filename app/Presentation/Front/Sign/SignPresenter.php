<?php

declare(strict_types=1);

namespace App\Presentation\Front\Sign;

use App\Model\DuplicateNameException;
use App\Model\UserFacade;
use App\Model\MailService;
use App\Presentation\Accessory\FormFactory;
use Nette;
use Nette\Application\Attributes\Persistent;
use Nette\Application\UI\Form;
use Nette\Utils\ArrayHash;

/**
 * Презентер для действий входа и регистрации.
 */
final class SignPresenter extends Nette\Application\UI\Presenter
{
	/**
	 * Хранит хэш предыдущей страницы для перенаправления после успешного входа.
	 */
	#[Persistent]
	public string $backlink = '';

	public function __construct(
		private UserFacade $userFacade,
		private FormFactory $formFactory,
		private MailService $mailService,
	) {
		parent::__construct();
	}

	/**
	 * Создает форму входа.
	 */
	protected function createComponentSignInForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addText('username', 'Uživatelské jméno:')
			->setRequired('Prosím zadejte své uživatelské jméno.');

		$form->addPassword('password', 'Heslo:')
			->setRequired('Prosím zadejte své heslo.')
			->setHtmlAttribute('autocomplete', 'current-password');

		$form->addSubmit('send', 'Přihlásit se');

		$form->onSuccess[] = function (Form $form, $data): void {
			// На 10 уровне мы должны явно проверить тип $data
			if (!$data instanceof ArrayHash) {
				return;
			}

			// Проверяем, что значения действительно строки, прежде чем передавать в login
			$username = $data->username;
			$password = $data->password;

			if (!is_string($username) || !is_string($password)) {
				return;
			}

			try {
				$this->getUser()->login($username, $password);
				
				$this->restoreRequest($this->backlink);

				if ($this->getUser()->isInRole('admin')) {
					$this->redirect(':Admin:Dashboard:default');
				} elseif ($this->getUser()->isInRole('moderator')) {
					$this->redirect(':Admin:Event:moderation');
				} else {
					$this->redirect(':Front:Home:default');
				}
			} catch (Nette\Security\AuthenticationException) {
				$form->addError('Uživatelské jméno nebo heslo je nesprávné.');
			}
		};

		return $form;
	}

	/**
	 * Создает форму регистрации с уведомлением по электронной почте.
	 */
	protected function createComponentSignUpForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addText('firstname', 'Jméno:')
			->setRequired('Prosím zadejte své jméno.');

		$form->addText('lastname', 'Příjmení:')
			->setRequired('Prosím zadejte své příjmení.');

		$form->addText('username', 'Zvolte si uživatelské jméno:')
			->setRequired('Prosím zvolte si uživatelské jméno.')
			->addRule(Form::MIN_LENGTH, 'Uživatelské jméno musí mít alespoň 3 znaky.', 3);

		$form->addText('birthdate', 'Datum narození:')
			->setHtmlType('date')
			->setRequired('Prosím zadejte své datum narození.');

		$form->addText('phone', 'Telefonní číslo:')
			->setHtmlAttribute('placeholder', '+1234567890')
			->addCondition(Form::FILLED)
				->addRule(
					Form::PATTERN,
					'Telefonní číslo musí začínat symbolem „+“ a obsahovat 7 až 15 číslic.',
					'^\+\d{7,15}$'
				);

		$form->addText('address', 'Ulice:');
		$form->addText('city', 'Město:');

		$form->addEmail('email', 'Váš e-mail:')
			->setRequired('Prosím zadejte svůj e-mail.');

		$form->addPassword('password', 'Heslo:')
			->setOption('description', 'alespoň 5 znaků')
			->setRequired('Prosím zadejte své heslo.')
			->addRule(Form::MIN_LENGTH, null, 5)
			->setHtmlAttribute('autocomplete', 'new-password');

		$form->addSubmit('send', 'Zaregistrovat se');

		$form->onSuccess[] = function (Form $form, $data): void {
			if (!$data instanceof ArrayHash) {
				return;
			}

			// Извлекаем значения и гарантируем PHPStan, что это строки
			$username = is_string($data->username) ? $data->username : '';
			$email = is_string($data->email) ? $data->email : '';
			$password = is_string($data->password) ? $data->password : '';
			$firstname = is_string($data->firstname) ? $data->firstname : null;
			$lastname = is_string($data->lastname) ? $data->lastname : null;
			$birthdate = is_string($data->birthdate) ? $data->birthdate : null;
			$phone = is_string($data->phone) ? $data->phone : null;
			$address = is_string($data->address) ? $data->address : null;
			$city = is_string($data->city) ? $data->city : null;

			if ($this->userFacade->getByUsername($username)) {
				$form->addError('Uživatelské jméno je již obsazeno.');
				return;
			}
			if ($this->userFacade->getByEmail($email)) {
				$form->addError('E-mail je již zaregistrován.');
				return;
			}

			try {
				$this->userFacade->add(
					$username,
					$email,
					$password,
					'user',
					$firstname,
					$lastname,
					$birthdate,
					$phone,
					$address,
					$city
				);

				$adminEmail = 'someone@gmail.com';

				try {
					$this->mailService->sendRegistrationEmail($email, $username, $adminEmail);
				} catch (\Throwable $e) {
					\Tracy\Debugger::log($e, \Tracy\Debugger::EXCEPTION);
				}

				try {
					$this->mailService->sendNewUserRegistrationNotification(
						$adminEmail,
						$username,
						$email,
						$firstname ?? '',
						$lastname ?? ''
					);
				} catch (\Throwable $e) {
					\Tracy\Debugger::log($e, \Tracy\Debugger::EXCEPTION);
				}

				$this->flashMessage('Registrace byla úspěšná. Nyní se můžete přihlásit.');
				$this->redirect(':Front:Home:default');
			} catch (DuplicateNameException) {
				$form->addError('Uživatelské jméno je již obsazeno.');
			}
		};

		return $form;
	}

	public function actionOut(): void
	{
		$this->getUser()->logout();
		$this->flashMessage('Byli jste odhlášeni.');
		$this->redirect('Home:default');
	}
}
