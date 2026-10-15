<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Game;

use App\Presentation\Accessory\RequireLoggedUser;
use Nette;
use Nette\Application\UI\Form;
use App\Model\EventFacade;
use Nette\Utils\ArrayHash;

final class GamePresenter extends Nette\Application\UI\Presenter
{
	use RequireLoggedUser;

	public function __construct(
		private EventFacade $eventFacade,
	) {
		parent::__construct();
	}

	public function startup(): void
	{
		parent::startup();
		// Проверка: только для администраторов
		if (!$this->getUser()->isInRole('admin')) {
			$this->flashMessage('Nemáte oprávnění ke správě her.', 'danger');
			$this->redirect(':Front:Home:default');
		}
	}

	public function renderDefault(): void
	{
		$this->template->games = $this->eventFacade->getGamesList();
	}

	public function actionEdit(int $id): void
	{
		$game = $this->eventFacade->getGameById($id);
		if (!$game) {
			$this->error('Hra nebyla nalezena');
		}
		$this['gameForm']->setDefaults($game->toArray());
	}

	// МЕТОД ДЛЯ УДАЛЕНИЯ ИГРЫ
	public function handleDelete(int $id): void
	{
		$this->eventFacade->deleteGame($id);
		$this->flashMessage('Hra byla úspěšně smazána.', 'success');
		$this->redirect('default');
	}

	protected function createComponentGameForm(): Form
	{
		$form = new Form;
		$form->addText('name', 'Název hry:')
			->setRequired('Zadejte prosím název hry.');
		
		$form->addText('genre', 'Žánr:');
		
		$form->addTextArea('description', 'Popis:');
		$form->addSubmit('save', 'Uložit změny');
		
		$form->onSuccess[] = function (Form $form, mixed $data): void {
			if (!$data instanceof ArrayHash) {
				return;
			}

			$idParam = $this->getParameter('id');
			$id = is_numeric($idParam) ? (int) $idParam : 0;

			// ИСПРАВЛЕНИЕ ОШИБКИ: передаем только нужные поля
			$dataArray = [
				'name' => $data->name,
				'genre' => $data->genre,
				'description' => $data->description,
			];

			$this->eventFacade->updateGame($id, $dataArray);
			$this->flashMessage('Údaje o hře byly úspěšně aktualizovány!', 'success');
			$this->redirect('default');
		};
		
		return $form;
	}
}
