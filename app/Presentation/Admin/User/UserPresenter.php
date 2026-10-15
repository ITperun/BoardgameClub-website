<?php

declare(strict_types=1);

namespace App\Presentation\Admin\User;

use Nette;
use App\Model\UserFacade;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\ArrayHash;
use Nette\Http\FileUpload;
use Nette\Security\SimpleIdentity;

final class UserPresenter extends Nette\Application\UI\Presenter
{
	public function startup(): void
	{
		parent::startup();
		
		$user = $this->getUser();
		if (!$user->isLoggedIn()) {
			$this->redirect(':Front:Sign:in');
		}

		if ($user->isInRole('admin')) {
			return;
		}

		if ($this->getAction() === 'edit') {
			return;
		}

		$this->flashMessage('Nemáte oprávnění prohlížet seznam uživatelů.', 'danger');
		$this->redirect(':Front:Home:default');
	}

	public function __construct(
		private UserFacade $userFacade
	) {
		parent::__construct();
	}

	public function renderDefault(): void
	{
		$this->template->userData = $this->userFacade->getAllUsers();
	}

	public function renderDetail(int $id): void
	{
		$user = $this->userFacade->getUserById($id);
		if (!$user instanceof ActiveRow) {
			$this->error('Uživatel nebyl nalezen');
		}
		$this->template->userData = $user;
	}

	public function renderEdit(?int $id = null): void
	{
		if (!$this->user->isInRole('admin')) {
			$this->flashMessage('Nemáte oprávnění upravovat uživatele.', 'danger');
			$this->redirect('default');
		}
		$targetId = $this->checkEditPermissions($id);
		
		$user = $this->userFacade->getUserById($targetId);
		if (!$user instanceof ActiveRow) {
			$this->error('Uživatel nebyl nalezen');
		}
		$this->template->userData = $user;
	}

	public function createComponentEditForm(): Nette\Application\UI\Form
	{
		$idParam = $this->getParameter('id');
		$targetId = $this->checkEditPermissions(is_numeric($idParam) ? (int) $idParam : null);

		$form = new Nette\Application\UI\Form;

		$form->addText('username', 'Uživatelské jméno')->setRequired();
		$form->addText('email', 'Email')->setRequired();
		$form->addPassword('password', 'Heslo (ponechte prázdné, pokud nechcete měnit)');

		$form->addUpload('image', 'Soubor (avatar)')
			->addRule(Nette\Forms\Form::IMAGE, 'Soubor musí být JPEG, PNG nebo GIF')
			->setRequired(false);

		if ($this->user->isInRole('admin')) {
			$roles = $this->userFacade->getAllRoles();
			$form->addSelect('role', 'Role', $roles)
				->setPrompt('Vyberte roli');
		}

		$existingUser = $this->userFacade->getUserById($targetId);
		if ($existingUser instanceof ActiveRow) {
			$formData = $existingUser->toArray();
			unset($formData['password']);
			$form->setDefaults($formData);

			if ($this->user->isInRole('admin')) {
				$roleControl = $form->getComponent('role', false);
				if ($roleControl instanceof Nette\Forms\Controls\SelectBox) {
					$roleControl->setDefaultValue($existingUser->role_id);
				}
			}
		}

		$form->addSubmit('send', 'Uložit');
		$form->onSuccess[] = [$this, 'editFormSucceeded'];

		return $form;
	}

	/**
	 * Исправлено: mixed перенесен в аннотацию
	 * @param Nette\Application\UI\Form $form
	 * @param mixed $values
	 */
	public function editFormSucceeded(Nette\Application\UI\Form $form, $values): void
	{
		if (!$values instanceof ArrayHash) {
			return;
		}

		/** @var array<string, mixed> $valuesArray */
		$valuesArray = (array) $values;
		$idParam = $this->getParameter('id');
		$userId = is_numeric($idParam) ? (int) $idParam : 0;
		$userData = $this->userFacade->getUserById($userId);

		if ($this->user->isInRole('admin') && isset($valuesArray['role'])) {
			$roleValue = $valuesArray['role'];
			$valuesArray['role_id'] = is_numeric($roleValue) ? (int)$roleValue : 0;
			unset($valuesArray['role']);
		}

		$image = $valuesArray['image'] ?? null;
		if ($image instanceof FileUpload && $image->isOk() && $image->getSize() > 0) {
			if ($userData instanceof ActiveRow && is_string($userData['image'])) {
				$oldImagePath = __DIR__ . '/../../../../www/' . $userData['image'];
				if (file_exists($oldImagePath)) {
					unlink($oldImagePath);
				}
			}

			$uploadDir = realpath(__DIR__ . '/../../../../') . '/www/upload/avatars/';
			if (!file_exists($uploadDir)) {
				mkdir($uploadDir, 0777, true);
			}

			$sanitizedName = $image->getSanitizedName();
			$uniqueName = uniqid() . "_" . $sanitizedName;
			$path = "upload/avatars/" . $uniqueName;

			$image->move($uploadDir . $uniqueName);
			$valuesArray['image'] = $path;
		} else {
			unset($valuesArray['image']);
		}

		$password = $values->password;
		if (is_string($password) && $password !== '') {
			$this->userFacade->changePassword($userId, $password);
		}
		unset($valuesArray['password']);

		$this->userFacade->edit($userId, $valuesArray);

		if ($this->user->getId() === $userId) {
			$identity = $this->user->getIdentity();
			if ($identity instanceof SimpleIdentity) {
				foreach ($valuesArray as $key => $value) {
					$identity->$key = $value;
				}
				$this->user->login($identity);
			}
		}

		$this->flashMessage('Profil byl úspěšně upraven.', 'success');
		$this->redirect('edit', ['id' => $userId]);
	}

	public function handleDelete(int $userId): void
	{
		if ($this->user->getId() === $userId) {
			$this->flashMessage('Nemůžete smazat sám sebe.', 'warning');
			$this->redirect('this');
		}

		if (!$this->user->isInRole('admin')) {
			$this->flashMessage('Nemáte oprávnění mazat uživatele.', 'danger');
			$this->redirect('this');
		}

		$this->userFacade->delete($userId);
		$this->flashMessage('Uživatel byl úspěšně smazán.', 'success');
		$this->redirect('User:default');
	}

	public function handleDeleteImage(int $userId): void
	{
		$userData = $this->userFacade->getUserById($userId);

		if ($userData instanceof ActiveRow && is_string($userData['image'])) {
			$imagePath = __DIR__ . '/../../../../www/' . $userData['image'];
			if (file_exists($imagePath)) {
				unlink($imagePath);
			}
		}

		$this->userFacade->edit($userId, ['image' => null]);

		$this->flashMessage('Obrázek k uživateli byl smazán.', 'success');
		$this->redirect('this');
	}

	private function checkEditPermissions(?int $id = null): int
	{
		$currentUserId = $this->user->getId();
		$currentUserIdInt = is_numeric($currentUserId) ? (int) $currentUserId : 0;

		if ($this->user->isInRole('admin')) {
			return $id ?? $currentUserIdInt;
		}

		if ($id !== null && $id !== $currentUserIdInt) {
			$this->flashMessage('Nemáte oprávnění upravovat cizí profil.', 'danger');
			$this->redirect('edit', ['id' => $currentUserIdInt]);
		}
		
		return $currentUserIdInt;
	}
}
