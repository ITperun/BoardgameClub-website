<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Event;

use Nette;
use Nette\Application\UI\Form;
use App\Model\EventFacade;
use App\Model\MailService;
use Tracy\Debugger;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\ArrayHash;

final class EventPresenter extends Nette\Application\UI\Presenter
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
		if (!$this->getSession()->isStarted()) {
			$this->getSession()->start();
		}

		$user = $this->getUser();

		if (!$user->isLoggedIn()) {
			$this->redirect(':Front:Sign:in');
		}

		if ($user->isInRole('admin') || $user->isInRole('moderator')) {
			return;
		}

		if (($user->isInRole('organizer') || $user->isInRole('creator')) && $this->getAction() === 'edit') {
			return;
		}

		$this->flashMessage('Do této sekce nemáte přístup.', 'danger');
		$this->redirect(':Front:Home:default');
	}

	public function renderDefault(): void
	{
		$this->template->events = $this->eventFacade->getAllEvents();
	}

	public function renderModeration(): void
	{
		$this->template->events = $this->eventFacade->getPendingEventsForModeration();
	}

	public function renderDetail(int $id): void
	{
		$event = $this->eventFacade->getEventById($id);
		if (!$event instanceof ActiveRow) {
			$this->error('Událost nebyla nalezena.');
		}
		$this->template->event = $event;
	}

	public function renderEdit(?int $id = null): void
	{
		if ($id !== null) {
			$event = $this->eventFacade->getEventById($id);

			if (!$event instanceof ActiveRow) {
				$this->error('Událost nebyla nalezena.');
			}

			$startTime = $event->start_time;
			$isPast = $startTime instanceof \DateTimeInterface ? $startTime < new \DateTime() : false;

			if ($isPast && !$this->getUser()->isInRole('admin')) {
				$this->flashMessage('Nelze upravovat již proběhlé události.', 'danger');
				$this->redirect(':Front:Organizer:default');
			}

			$userId = $this->getUser()->getId();
			$userIdInt = is_int($userId) ? $userId : 0;
			$isManager = $this->getUser()->isInRole('admin') || $this->getUser()->isInRole('moderator');

			$organizerId = $event->organizer_id;
			if (!$isManager && is_numeric($organizerId) && (int)$organizerId !== $userIdInt) {
				$this->error('Nemáte oprávnění upravovat tuto událost.', Nette\Http\IResponse::S403_FORBIDDEN);
			}

			$this['eventForm']->setDefaults($event->toArray());
		}
	}

	protected function createComponentEventForm(): Form
	{
		$form = new Form;
		$form->addText('title', 'Název události:')->setRequired('Zadejte název.');
		$form->addText('location', 'Místo:')->setRequired('Zadejte místo.');
		$form->addText('start_time', 'Začátek:')->setHtmlType('datetime-local')->setRequired();
		$form->addText('end_time', 'Konec:')->setHtmlType('datetime-local');
		
		$games = $this->eventFacade->getAllGames();
		$form->addSelect('game_id', 'Hra:', $games)->setPrompt('Bez hry');
		$form->addText('new_game', 'Nová hra:');
		$form->addTextarea('description', 'Popis:')->setRequired();
		
		$form->addSelect('status', 'Stav zápisu:', [
			'open' => '🟢 Zápis otevřen',
			'closed' => '🔴 Zápis uzavřen'
		])->setDefaultValue('open');

		$form->addHidden('id');
		$form->addSubmit('save', 'Uložit')->setHtmlAttribute('class', 'btn btn-primary');
		$form->onSuccess[] = [$this, 'eventFormSucceeded'];
		return $form;
	}

	/**
	 * @param Form $form
	 * @param mixed $values
	 */
	public function eventFormSucceeded(Form $form, $values): void
	{
		if (!$values instanceof ArrayHash) {
			return;
		}

		$userId = $this->getUser()->getId();
		$userIdInt = is_int($userId) ? $userId : 0;
		$isManager = $this->getUser()->isInRole('admin') || $this->getUser()->isInRole('moderator');

		$eventIdParam = $values->id;
		$eventId = is_numeric($eventIdParam) ? (int)$eventIdParam : null;

		if ($eventId !== null) {
			$event = $this->eventFacade->getEventById($eventId);
			if ($event instanceof ActiveRow && !$isManager) {
				$orgId = $event->organizer_id;
				if (is_numeric($orgId) && (int)$orgId !== $userIdInt) {
					$this->error('Nemáte oprávnění.');
				}
			}
		}

		$startTimeStr = is_string($values->start_time) ? $values->start_time : '';
		try {
			$start = new \DateTime($startTimeStr);
		} catch (\Exception $e) {
			$form->addError('Neplatný formát začátku.');
			return;
		}

		$endTimeStr = is_string($values->end_time) ? $values->end_time : '';
		$end = null;
		if ($endTimeStr !== '') {
			try {
				$end = new \DateTime($endTimeStr);
			} catch (\Exception $e) {
				$form->addError('Neplatný formát konce.');
				return;
			}
		}

		if ($end && $start >= $end) {
			$form->addError('Datum začátku musí být před koncem.');
			return;
		}

		$newGame = is_string($values->new_game) ? trim($values->new_game) : '';
		$gameId = null;
		if ($newGame !== '') {
			$gameId = $this->eventFacade->createGame($newGame);
		} else {
			$formGameId = $values->game_id;
			$gameId = is_numeric($formGameId) ? (int)$formGameId : null;
		}

		$data = [
			'title' => is_string($values->title) ? $values->title : '',
			'location' => is_string($values->location) ? $values->location : '',
			'start_time' => $startTimeStr,
			'end_time' => $endTimeStr !== '' ? $endTimeStr : null,
			'description' => is_string($values->description) ? $values->description : '',
			'game_id' => $gameId,
			'status' => is_string($values->status) ? $values->status : 'open',
		];

		if ($eventId !== null) {
			$this->eventFacade->updateEvent($eventId, $data, $userIdInt);
			if (!$isManager) {
				$this->flashMessage('Událost upravena a čeká na nové schválení.', 'warning');
			} else {
				$this->flashMessage('Událost byla změněna.', 'success');
			}
		} else {
			$this->eventFacade->createEvent($data, $userIdInt);
			$this->flashMessage('Událost byla vytvořena.', 'success');
		}

		if ($isManager) {
			$this->redirect('default');
		} else {
			$this->redirect(':Front:Organizer:default');
		}
	}

	public function renderModerationDetail(int $id): void
	{
		$event = $this->eventFacade->getEventById($id);
		if (!$event instanceof ActiveRow) {
			$this->error('Událost nebyla nalezena.');
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
		$form->addText('title', 'Název:')->setRequired();
		$form->addText('location', 'Místo:')->setRequired();
		$form->addText('start_time', 'Začátek:')->setHtmlType('datetime-local')->setRequired();
		$form->addText('end_time', 'Konec:')->setHtmlType('datetime-local');
		$games = $this->eventFacade->getAllGames();
		$form->addSelect('game_id', 'Hra:', $games)->setPrompt('Bez hry');
		$form->addText('new_game', 'Nová hra:');
		$form->addTextarea('description', 'Popis:')->setRequired();
		$form->addHidden('id');

		$form->addSubmit('approve', '✅ Schválit')->setHtmlAttribute('class', 'btn btn-success me-2');
		$form->addSubmit('reject', '❌ Zamítnout')->setHtmlAttribute('class', 'btn btn-danger');

		$form->onSuccess[] = [$this, 'moderationFormSucceeded'];
		return $form;
	}

	/**
	 * @param Form $form
	 * @param mixed $values
	 */
	public function moderationFormSucceeded(Form $form, $values): void
	{
		if (!$values instanceof ArrayHash) {
			return;
		}

		if (!$this->getUser()->isInRole('admin') && !$this->getUser()->isInRole('moderator')) {
			$this->error('Forbidden');
		}

		$moderatorId = $this->getUser()->getId();
		$moderatorIdInt = is_int($moderatorId) ? $moderatorId : 0;

		$eventIdParam = $values->id;
		$eventId = is_numeric($eventIdParam) ? (int)$eventIdParam : 0;
		$event = $this->eventFacade->getEventById($eventId);

		if (!$event instanceof ActiveRow) {
			$this->error('Událost nebyla nalezena.');
		}

		$newGame = is_string($values->new_game) ? trim($values->new_game) : '';
		$gameId = null;
		if ($newGame !== '') {
			$gameId = $this->eventFacade->createGame($newGame);
		} else {
			$formGameId = $values->game_id;
			$gameId = is_numeric($formGameId) ? (int)$formGameId : null;
		}

		$data = [
			'title' => is_string($values->title) ? $values->title : '',
			'location' => is_string($values->location) ? $values->location : '',
			'start_time' => is_string($values->start_time) ? $values->start_time : '',
			'end_time' => is_string($values->end_time) && $values->end_time !== '' ? $values->end_time : null,
			'description' => is_string($values->description) ? $values->description : '',
			'game_id' => $gameId,
		];
		$this->eventFacade->updateEvent($eventId, $data, $moderatorIdInt);

		$approveBtn = $form->getComponent('approve', false);
		$rejectBtn = $form->getComponent('reject', false);

		$isApproveClicked = $approveBtn instanceof \Nette\Forms\Controls\SubmitButton && $approveBtn->isSubmittedBy();
		$isRejectClicked = $rejectBtn instanceof \Nette\Forms\Controls\SubmitButton && $rejectBtn->isSubmittedBy();

		$status = '';
		if ($isApproveClicked) {
			$status = 'approved';
			$this->eventFacade->moderateEvent($eventId, $status, $moderatorIdInt);
			$this->flashMessage('Událost byla schválena.', 'success');
		} elseif ($isRejectClicked) {
			$status = 'rejected';
			$this->eventFacade->moderateEvent($eventId, $status, $moderatorIdInt);
			$this->flashMessage('Událost byla zamítnuta.', 'warning');
		}

		if ($status !== '') {
			$organizer = $event->ref('users', 'organizer_id');
			if ($organizer instanceof ActiveRow) {
				$orgEmail = is_string($organizer->email) ? $organizer->email : '';
				$orgUsername = is_string($organizer->username) ? $organizer->username : '';
				$eventTitle = is_string($event->title) ? $event->title : '';

				if ($orgEmail !== '') {
					try {
						$this->mailService->sendModerationEmail($orgEmail, $orgUsername, $eventTitle, $status);
					} catch (\Exception $e) {
						Debugger::log($e, Debugger::EXCEPTION);
					}
				}
			}
		}

		$this->redirect('moderation');
	}

	public function handleDelete(int $id): void
	{
		if (!$this->getUser()->isInRole('admin')) {
			$this->flashMessage('Nemáte oprávnění mazat události.', 'danger');
			$this->redirect('this');
		}

		$this->eventFacade->deleteEvent($id);
		$this->flashMessage('Událost byla smazána.', 'info');
		$this->redirect('this');
	}
}
