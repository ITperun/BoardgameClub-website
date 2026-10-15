<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Event;

use Nette;
use Nette\Application\UI\Form;
use App\Model\EventFacade;
use App\Model\MailService;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\ArrayHash;

final class ModerationPresenter extends Nette\Application\UI\Presenter
{
	public function __construct(
		private EventFacade $eventFacade,
		private MailService $mailService
	) {
		parent::__construct();
	}

	public function startup(): void
	{
		parent::startup();
		// Доступ для админов, модераторов и авторизованных пользователей с ролью organizer
		if (!$this->user->isLoggedIn()) {
			$this->error('Neoprávněný přístup.', Nette\Http\IResponse::S403_FORBIDDEN);
		}
		
		// Админы и модераторы имеют везде доступ
		if ($this->user->isInRole('admin') || $this->user->isInRole('moderator')) {
			return;
		}
		
		// Организаторы могут видеть только свои события
		if (!$this->user->isInRole('organizer')) {
			$this->error('Neoprávněný přístup.', Nette\Http\IResponse::S403_FORBIDDEN);
		}
	}

	public function renderDefault(): void
	{
		// Админы и модераторы видят все ожидающие события
		if ($this->user->isInRole('admin') || $this->user->isInRole('moderator')) {
			$this->template->events = $this->eventFacade->getPendingEventsForModeration();
		} else {
			// Организаторы видят только свои ожидающие события
			$userId = $this->user->getId();
			if (is_int($userId)) {
				$this->template->events = $this->eventFacade->getPendingEventsByOrganizer($userId);
			} else {
				$this->template->events = [];
			}
		}
	}

	public function renderDetail(int $id): void
	{
		$event = $this->eventFacade->getEventById($id);
		
		// Строгая проверка для PHPStan 10
		if (!$event instanceof ActiveRow || $event->status !== 'pending') {
			$this->error('Událost nebyla nalezena nebo není ke schválení.');
		}

		// Проверка прав: Админы/Модераторы могут видеть все события, Организаторы - только свои
		if (!$this->user->isInRole('admin') && !$this->user->isInRole('moderator')) {
			if ($event->organizer_id !== $this->user->getId()) {
				$this->error('Nemáte oprávnění zobrazit tuto událost.', Nette\Http\IResponse::S403_FORBIDDEN);
			}
		}

		$startTime = $event->start_time;
		$endTime = $event->end_time;

		$this['moderationForm']->setDefaults([
			'title' => $event->title,
			'description' => $event->description,
			'location' => $event->location,
			'start_time' => $startTime instanceof \DateTimeInterface ? $startTime->format('Y-m-d\TH:i') : null,
			'end_time' => $endTime instanceof \DateTimeInterface ? $endTime->format('Y-m-d\TH:i') : null,
			'game_id' => $event->game_id,
			'id' => $event->id,
		]);

		$this->template->event = $event;
		$this->template->organizer = $event->ref('users', 'organizer_id');
	}

	protected function createComponentModerationForm(): Form
	{
		$form = new Form;

		$form->addText('title', 'Název události:')
			->setRequired('Zadejte název.');
		$form->addText('location', 'Místo:')
			->setRequired('Zadejte místo konání.');
		$form->addText('start_time', 'Začátek:')
			->setHtmlType('datetime-local')
			->setRequired();
		$form->addText('end_time', 'Konec:')
			->setHtmlType('datetime-local');

		$games = $this->eventFacade->getAllGames();
		$form->addSelect('game_id', 'Hra:', $games)
			->setPrompt('Bez hry');
		$form->addText('new_game', 'Nová hra:');

		$form->addTextarea('description', 'Popis:')
			->setRequired();

		$form->addHidden('id');

		// Pouze adminisítratoři a moderátoři mohou události schválit/zamítnout
		if ($this->user->isInRole('admin') || $this->user->isInRole('moderator')) {
			$form->addSubmit('approve', 'Schválit')
				->setHtmlAttribute('class', 'btn btn-success');
			$form->addSubmit('reject', 'Zamítnout')
				->setHtmlAttribute('class', 'btn btn-danger ms-2');
		} else {
			// Organizátoři vidí pouze tlačítko Uložit
			$form->addSubmit('save', 'Uložit')
				->setHtmlAttribute('class', 'btn btn-primary');
		}

		$form->onSuccess[] = [$this, 'moderationFormSucceeded'];
		return $form;
	}

	/**
	 * Исправлено: mixed в аннотации и отсутствие типа в сигнатуре для предотвращения ReflectionException
	 * @param Form $form
	 * @param mixed $values
	 */
	public function moderationFormSucceeded(Form $form, $values): void
	{
		// Проверка instanceof остается для правильной работы PHPStan
		if (!$values instanceof ArrayHash) {
			return;
		}

		$moderatorId = $this->getUser()->getId();
		if (!is_int($moderatorId)) {
			$this->error('Neoprávněný přístup.');
		}

		$eventIdParam = $values->id;
		$eventId = is_numeric($eventIdParam) ? (int)$eventIdParam : 0;
		$event = $this->eventFacade->getEventById($eventId);
		
		if (!$event instanceof ActiveRow) {
			$this->error('Událost nebyla nalezena.');
		}
		
		// 1. Проверка прав доступа
		$userIsAdmin = $this->getUser()->isInRole('admin');
		$userIsModerator = $this->getUser()->isInRole('moderator');
		$userIsEventCreator = $event->organizer_id === $moderatorId;
		
		if (!$userIsAdmin && !$userIsModerator && !$userIsEventCreator) {
			$this->error('Nemáte oprávnění upravovat tuto událost.', Nette\Http\IResponse::S403_FORBIDDEN);
		}
		
		// Безопасное получение кнопок для проверки их нажатия
		$approveBtn = $form->getComponent('approve', false);
		$rejectBtn = $form->getComponent('reject', false);

		$isApproveClicked = $approveBtn instanceof \Nette\Forms\Controls\SubmitButton && $approveBtn->isSubmittedBy();
		$isRejectClicked = $rejectBtn instanceof \Nette\Forms\Controls\SubmitButton && $rejectBtn->isSubmittedBy();
		$isApprovingOrRejecting = $isApproveClicked || $isRejectClicked;

		if (!$userIsAdmin && !$userIsModerator && $isApprovingOrRejecting) {
			$this->error('Nemáte oprávnění schvalovat nebo zamítavat události.');
		}
		
		$organizer = $event->ref('users', 'organizer_id');

		// 3. Валидация дат
		$startTimeStr = is_string($values->start_time) ? $values->start_time : '';
		try {
			$start = new \DateTime($startTimeStr);
		} catch (\Exception $e) {
			$form->addError('Neplatný formát začátku.');
			return;
		}

		$end = null;
		$endTimeStr = is_string($values->end_time) ? $values->end_time : '';
		if ($endTimeStr !== '') {
			try {
				$end = new \DateTime($endTimeStr);
			} catch (\Exception $e) {
				$form->addError('Neplatný formát konce.');
				return;
			}
		}

		if ($end && $start >= $end) {
			$form->addError('Začátek musí být před koncem.');
			return;
		}

		// 4. Логика Игры
		$gameId = null;
		$newGame = is_string($values->new_game) ? trim($values->new_game) : '';
		if ($newGame !== '') {
			$gameId = $this->eventFacade->createGame($newGame);
		} else {
			$gameId = is_numeric($values->game_id) ? (int)$values->game_id : null;
		}

		// 5. Сборка данных
		$data = [
			'title' => is_string($values->title) ? $values->title : '',
			'location' => is_string($values->location) ? $values->location : '',
			'start_time' => $startTimeStr,
			'end_time' => $endTimeStr !== '' ? $endTimeStr : null,
			'description' => is_string($values->description) ? $values->description : '',
			'game_id' => $gameId,
		];
		
		// 6. Сохранение изменений
		$this->eventFacade->updateEvent($eventId, $data, $moderatorId);

		// 7. Применение вердикта и отправка письма (только для админов/модераторов)
		$status = '';
		if ($isApproveClicked) {
			$status = 'approved';
			$this->eventFacade->moderateEvent($eventId, $status, $moderatorId);
			$this->flashMessage('Událost schválena.', 'success');
		} elseif ($isRejectClicked) {
			$status = 'rejected';
			$this->eventFacade->moderateEvent($eventId, $status, $moderatorId);
			$this->flashMessage('Událost zamítnuta (skryta).', 'warning');
		} else {
			// Организаторы просто редактируют событие
			if ($userIsEventCreator) {
				$this->flashMessage('Událost uložena.', 'success');
			}
		}

		// Отправка уведомления организатору
		if ($status !== '' && $organizer instanceof ActiveRow) {
			$orgEmail = is_string($organizer->email) ? $organizer->email : '';
			$orgUsername = is_string($organizer->username) ? $organizer->username : '';
			$eventTitle = is_string($event->title) ? $event->title : '';

			if ($orgEmail !== '') {
				try {
					$this->mailService->sendModerationEmail(
						$orgEmail,
						$orgUsername,
						$eventTitle,
						$status
					);
				} catch (\Exception $e) {
					\Tracy\Debugger::log($e, \Tracy\Debugger::EXCEPTION);
				}
			}
		}

		$this->redirect('default');
	}
}
