<?php

declare(strict_types=1);

namespace App\Presentation\Front\Profile;

use Nette;
use Nette\Application\UI\Form;
use App\Model\UserFacade;
use App\Presentation\Accessory\RequireLoggedUser;

final class ProfilePresenter extends Nette\Application\UI\Presenter
{
	// Трейт для защиты (только залогиненные могут видеть профиль)
	use RequireLoggedUser;

	public function __construct(
		private UserFacade $userFacade
	) {
		parent::__construct();
	}

	public function renderDefault(): void
	{
		// Получаем актуальные данные пользователя из БД
		$userId = $this->getUser()->getId();
		
		if (!is_int($userId)) {
			$this->getUser()->logout(true);
			$this->redirect(':Front:Sign:in');
		}

		$userRow = $this->userFacade->getUserById($userId);

		if (!$userRow) {
			$this->getUser()->logout(true);
			$this->redirect(':Front:Sign:in');
		}

		$this->template->userData = $userRow;
	}

	protected function createComponentProfileForm(): Form
	{
		$form = new Form;

		$form->addUpload('image', 'Nový avatar:')
			->addRule(Form::IMAGE, 'Avatar musí být ve formátu PNG.')
			->addRule(Form::MIME_TYPE, 'Avatar musí být ve formátu PNG.', 'image/png')
			->setRequired(false);

		$form->addGroup('Změna hesla');
		
		$form->addPassword('current_password', 'Současné heslo:')
			->setOption('description', 'Vyplňte pouze pokud chcete změnit heslo.');

		// Строгая типизация для использования в addConditionOn
		/** @var \Nette\Forms\Controls\TextInput $currentPasswordInput */
		$currentPasswordInput = $form['current_password'];

		$form->addPassword('new_password', 'Nové heslo:')
			->addConditionOn($currentPasswordInput, Form::FILLED)
				->setRequired('Pro změnu hesla zadejte nové heslo.')
				->addRule(Form::MIN_LENGTH, 'Heslo musí mít alespoň %d znaků.', 5);

		/** @var \Nette\Forms\Controls\TextInput $newPasswordInput */
		$newPasswordInput = $form['new_password'];

		$form->addPassword('new_password_verify', 'Ověření nového hesla:')
			->addConditionOn($newPasswordInput, Form::FILLED)
				->setRequired('Zadejte nové heslo znovu pro kontrolu.')
				->addRule(Form::EQUAL, 'Hesla se neshodují.', $newPasswordInput);

		$form->addSubmit('save', 'Uložit změny')
			->setHtmlAttribute('class', 'btn btn-primary mt-3');

		$form->onSuccess[] = [$this, 'profileFormSucceeded'];

		return $form;
	}

	/**
	 * @param Form $form
	 * @param mixed $values
	 */
	public function profileFormSucceeded(Form $form, $values): void
	{
		if (!$values instanceof \Nette\Utils\ArrayHash) {
			return;
		}

		$userId = $this->getUser()->getId();
		// PHPStan: Проверяем, что ID — это целое число (исправляет ошибки :143 и :149)
		if (!is_int($userId)) {
			return;
		}

		// Исправляем ошибку :96 (Undefined variable) и :131 (тип для verifyPassword)
		$userRow = $this->userFacade->getUserById($userId);
		if (!$userRow instanceof \Nette\Database\Table\ActiveRow) {
			return;
		}

		$dataToUpdate = [];

		// 1. Обработка Аватара
		$imageUpload = $values->image ?? null;
		if ($imageUpload instanceof \Nette\Http\FileUpload && $imageUpload->hasFile() && $imageUpload->isOk()) {
			// PHPStan: Теперь $userRow точно ActiveRow, можно обращаться к полям (исправляет :106)
			$oldImage = isset($userRow->image) && is_string($userRow->image) ? $userRow->image : null;
			
			if ($oldImage !== null) {
				$oldPath = __DIR__ . '/../../../../www/' . $oldImage;
				if (file_exists($oldPath)) {
					unlink($oldPath);
				}
			}

			// Сохраняем новый
			$uploadDir = realpath(__DIR__ . '/../../../../') . '/www/upload/avatars/';
			if (!file_exists($uploadDir)) {
				mkdir($uploadDir, 0777, true);
			}

			$sanitizedName = $imageUpload->getSanitizedName();
			$uniqueName = uniqid() . "_" . $sanitizedName;
			
			$imageUpload->move($uploadDir . $uniqueName);
			$dataToUpdate['image'] = "upload/avatars/" . $uniqueName;
		}

		// 2. Обработка Пароля
		$currentPassword = isset($values->current_password) && is_string($values->current_password)
			? $values->current_password
			: '';
			
		if ($currentPassword !== '') {
			// Проверяем старый пароль
			if (!$this->userFacade->verifyPassword($userRow, $currentPassword)) {
				/** @var \Nette\Forms\Controls\TextInput $currentPasswordControl */
				$currentPasswordControl = $form['current_password'];
				$currentPasswordControl->addError('Zadané současné heslo není správné.');
				return;
			}

			// Если пароль верный и введен новый - меняем
			$newPassword = isset($values->new_password) && is_string($values->new_password)
				? $values->new_password
				: '';
				
			if ($newPassword !== '') {
				$this->userFacade->changePassword($userId, $newPassword);
				$this->flashMessage('Heslo bylo úspěšně změněno.', 'success');
			}
		}

		if (!empty($dataToUpdate)) {
			$this->userFacade->edit($userId, $dataToUpdate);
			$this->flashMessage('Profil byl aktualizován.', 'success');
		}

		// 4. Обновляем данные в сессии (Identity)
		if (!empty($dataToUpdate)) {
			$identity = $this->getUser()->getIdentity();
			if ($identity instanceof \Nette\Security\SimpleIdentity) {
				foreach ($dataToUpdate as $key => $val) {
					$identity->$key = $val;
				}
			}
		}

		$this->redirect('this');
	}
}
