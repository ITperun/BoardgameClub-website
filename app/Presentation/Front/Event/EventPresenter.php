<?php

declare(strict_types=1);

namespace App\Presentation\Front\Event;

use Nette;
use Nette\Application\UI\Form;
use App\Model\EventFacade;
use App\Model\MailService;
use Nette\Utils\ArrayHash;
use Nette\Database\Table\ActiveRow;
use Nette\Security\SimpleIdentity;

final class EventPresenter extends Nette\Application\UI\Presenter
{
	private EventFacade $eventFacade;
	private MailService $mailService;

	public function __construct(EventFacade $eventFacade, MailService $mailService)
	{
		parent::__construct();
		$this->eventFacade = $eventFacade;
		$this->mailService = $mailService;
	}

	public function startup(): void
	{
		parent::startup();
		
		if (!$this->getSession()->isStarted()) {
			$this->getSession()->start();
		}

		// Инициализация форм для предотвращения конфликтов заголовков
		\Nette\Forms\Form::initialize();
	}

	// --- ПРОСМОТР И СПИСКИ ---

	public function renderDefault(): void
	{
		$this->template->events = $this->eventFacade->getUpcomingEvents();
	}

	public function renderDetail(int $id): void
	{
		$event = $this->eventFacade->getEventById($id);
		
		// 10 уровень требует строгой проверки типа из БД
		if (!$event instanceof ActiveRow) {
			$this->error('Událost nebyla nalezena');
		}

		$isAdminOrMod = $this->user->isInRole('admin') || $this->user->isInRole('moderator');
		$isOrganizer = $this->user->isLoggedIn() && $event->organizer_id === $this->user->getId();

		if ($event->status !== 'approved' && !$isAdminOrMod && !$isOrganizer) {
			$this->error('Tato událost ještě nebyla schválena.', Nette\Http\IResponse::S403_FORBIDDEN);
		}

		$participants = $this->eventFacade->getParticipants($id);
		$this->template->participants = $participants;
		$this->template->participantsCount = count($participants);

		$eventStartTime = $event->start_time;
		$isPast = $eventStartTime instanceof \DateTimeInterface ? $eventStartTime < new \DateTime() : false;
		
		$identity = $this->user->getIdentity();
		$userId = null;
		$userRole = null;
		
		if ($identity instanceof SimpleIdentity) {
			$userId = is_int($identity->id) ? $identity->id : null;
			$userRole = is_string($identity->role) ? $identity->role : null;
		}
		
		$isJoined = is_int($userId) ? $this->eventFacade->isUserJoined($id, $userId) : false;
		
		$isAdmin = $userRole === 'admin';
		$isModerator = $userRole === 'moderator';
		$isOrganizer = is_int($userId) && $event->organizer_id === $userId;

		$canViewChat = $isJoined || $isAdmin || $isOrganizer || $isModerator;
		$canChat = !$isPast && ($isJoined || $isAdmin || $isModerator || $isOrganizer);

		$chatMessages = $canViewChat ? $this->eventFacade->getChatMessages($id) : [];

		$blockedUsers = [];
		if ($isOrganizer || $isAdmin || $isModerator) {
			$blockedUsers = $this->eventFacade->getBlockedUsers($id);
		}
		$this->template->blockedUsers = $blockedUsers;

		$this->template->event = $event;
		$this->template->isJoined = $isJoined;
		$this->template->canViewChat = $canViewChat;
		$this->template->canChat = $canChat;
		$this->template->chatMessages = $chatMessages;
		$this->template->isAdmin = $isAdmin;
		$this->template->isModerator = $isModerator;
		$this->template->isOrganizer = $isOrganizer;
		$this->template->isPast = $isPast;
	}

	// --- СОЗДАНИЕ СОБЫТИЯ ---

	public function renderCreate(): void
	{
		$identity = $this->user->getIdentity();
		$role = $identity instanceof SimpleIdentity && is_string($identity->role) ? $identity->role : null;
		
		if (!$this->user->isLoggedIn() || !in_array($role, ['admin', 'creator', 'organizer', 'moderator'], true)) {
			$this->error('Nemáte oprávnění pro vytvoření události.', Nette\Http\IResponse::S403_FORBIDDEN);
		}
	}

	protected function createComponentCreateEventForm(): Form
	{
		$form = new Form;

		$form->addText('title', 'Název události:')->setRequired('Zadejte název.');
		$form->addText('location', 'Místo:')->setRequired('Zadejte místo konání.');
		$form->addText('start_time', 'Začátek:')->setHtmlType('datetime-local')->setRequired();
		$form->addText('end_time', 'Konec:')->setHtmlType('datetime-local');

		$games = $this->eventFacade->getAllGames();
		$form->addSelect('game_id', 'Hra:', $games)->setPrompt('Bez hry');
		$form->addText('new_game', 'Nová hra (pokud není v seznamu):');
		$form->addTextarea('description', 'Popis:')->setRequired();

		$form->addSubmit('save', 'Vytvořit událost')->setHtmlAttribute('class', 'btn btn-primary');

		$form->onSuccess[] = [$this, 'createEventFormSucceeded'];
		return $form;
	}

	/**
	 * Исправлено: mixed в аннотации и отсутствие типа в сигнатуре
	 * @param Form $form
	 * @param mixed $values
	 */
	public function createEventFormSucceeded(Form $form, $values): void
	{
		if (!$values instanceof ArrayHash) {
			return;
		}

		$identity = $this->user->getIdentity();
		if (!$identity instanceof SimpleIdentity) {
			$this->flashMessage('Nemáte oprávnění k vytvoření události.', 'danger');
			$this->redirect('Home:default');
		}

		$role = is_string($identity->role) ? $identity->role : '';
		$userId = is_int($identity->id) ? $identity->id : 0;
		$username = is_string($identity->username) ? $identity->username : '';

		if (!in_array($role, ['admin', 'creator', 'organizer', 'moderator'], true)) {
			$this->flashMessage('Nemáte oprávnění k vytvoření události.', 'danger');
			$this->redirect('Home:default');
		}

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
			$form->addError('Datum začátku musí být před datem konce.');
			return;
		}

		$gameId = null;
		$newGame = is_string($values->new_game) ? trim($values->new_game) : '';
		if ($newGame !== '') {
			$gameId = $this->eventFacade->createGame($newGame);
		} else {
			$gameId = is_numeric($values->game_id) ? (int)$values->game_id : null;
		}

		$title = is_string($values->title) ? $values->title : '';
		
		$eventData = [
			'title' => $title,
			'description' => is_string($values->description) ? $values->description : '',
			'location' => is_string($values->location) ? $values->location : '',
			'start_time' => $startTimeStr,
			'end_time' => $endTimeStr !== '' ? $endTimeStr : null,
			'game_id' => $gameId,
			'status' => 'open'
		];

		$this->eventFacade->createEvent($eventData, $userId);

		try {
			$this->mailService->sendNewEventRequestNotification('someone@gmail.com', $title, $username);
		} catch (\Exception $e) {
			\Tracy\Debugger::log($e);
		}

		$this->flashMessage('Událost byla vytvořena a čeká na schválení moderátorem.', 'success');
		$this->redirect('Home:default');
	}

	// --- УЧАСТИЕ В СОБЫТИИ (JOIN / LEAVE) ---

	public function handleJoin(int $id): void
	{
		if (!$this->user->isLoggedIn()) {
			$this->flashMessage('Pro připojení k události se musíte přihlásit.', 'warning');
			$this->redirect(':Front:Sign:in');
		}

		$userId = $this->user->getId();
		if (!is_int($userId)) {
			$this->redirect(':Front:Sign:in');
		}

		if ($this->eventFacade->isUserBlocked($id, $userId)) {
			$this->flashMessage(
				'Nemůžete se připojit k této události, protože jste byli zablokováni organizátorem.',
				'danger'
			);
			$this->redirect('this');
		}

		if (!$this->eventFacade->isUserJoined($id, $userId)) {
			$this->eventFacade->joinEvent($id, $userId);
			$this->flashMessage('Byla jste přihlášena k události.', 'success');
		} else {
			$this->flashMessage('Už jste přihlášena k této události.', 'info');
		}

		$this->redirect('this');
	}

	public function handleLeave(int $id): void
	{
		if (!$this->user->isLoggedIn()) {
			$this->flashMessage('Pro odhlášení se musíte přihlásit.', 'warning');
			$this->redirect(':Front:Sign:in');
		}

		$userId = $this->user->getId();
		if (!is_int($userId)) {
			$this->redirect(':Front:Sign:in');
		}

		if ($this->eventFacade->isUserJoined($id, $userId)) {
			$this->eventFacade->leaveEvent($id, $userId);
			$this->flashMessage('Byl jste odhlášen z události.', 'info');
		} else {
			$this->flashMessage('Nejste přihlášen k této události.', 'warning');
		}

		$this->redirect('this');
	}

	// --- УПРАВЛЕНИЕ УЧАСТНИКАМИ (KICK / BLOCK) ---

	public function handleKick(int $userId): void
	{
		$eventIdParam = $this->getParameter('id');
		if (!is_numeric($eventIdParam)) {
			$this->redirect('this');
		}
		$eventId = (int) $eventIdParam;
		
		$this->checkManagementPermissions($eventId);

		if ($userId === $this->user->getId()) {
			$this->flashMessage('Nemůžete vyhodit sami sebe.', 'warning');
			$this->redirect('this');
		}

		$this->eventFacade->leaveEvent($eventId, $userId);
		$this->flashMessage('Uživatel byl odebrán z události.', 'info');
		$this->redirect('this');
	}

	public function handleBlock(int $userId): void
	{
		$eventIdParam = $this->getParameter('id');
		if (!is_numeric($eventIdParam)) {
			$this->redirect('this');
		}
		$eventId = (int) $eventIdParam;
		
		$this->checkManagementPermissions($eventId);

		if ($userId === $this->user->getId()) {
			$this->flashMessage('Nemůžete zablokovat sami sebe.', 'warning');
			$this->redirect('this');
		}

		$this->eventFacade->blockUser($eventId, $userId);
		$this->eventFacade->leaveEvent($eventId, $userId);
		
		$this->flashMessage('Uživatel byl zablokován a odebrán z události.', 'success');
		$this->redirect('this');
	}

	private function checkManagementPermissions(int $eventId): void
	{
		if (!$this->user->isLoggedIn()) {
			$this->error('Přístup odepřen.');
		}

		$event = $this->eventFacade->getEventById($eventId);
		if (!$event instanceof ActiveRow) {
			$this->error('Událost nenalezena.');
		}

		$identity = $this->user->getIdentity();
		$userId = $identity instanceof SimpleIdentity && is_int($identity->id) ? $identity->id : null;

		$isOrganizer = $userId !== null && $event->organizer_id === $userId;
		$isAdmin = $this->user->isInRole('admin');
		$isModerator = $this->user->isInRole('moderator');

		if (!$isOrganizer && !$isAdmin && !$isModerator) {
			$this->flashMessage('Nemáte oprávnění spravovat účastníky.', 'danger');
			$this->redirect('this');
		}
	}

	// --- ЧАТ ---

	protected function createComponentChatForm(): Form
	{
		$form = new Form;
		$form->addText('content', 'Zpráva:')->setRequired('Napište zprávu.');
		$form->addSubmit('send', 'Odeslat');
		$form->onSuccess[] = [$this, 'chatFormSucceeded'];
		return $form;
	}

	/**
	 * Исправлено: mixed в аннотации и отсутствие типа в сигнатуре
	 * @param Form $form
	 * @param mixed $values
	 */
	public function chatFormSucceeded(Form $form, $values): void
	{
		if (!$values instanceof ArrayHash) {
			return;
		}

		if (!$this->user->isLoggedIn()) {
			$this->flashMessage('Pro odeslání zprávy se musíte přihlásit.', 'danger');
			$this->redirect('this');
		}

		$eventIdParam = $this->getParameter('id');
		if (!is_numeric($eventIdParam)) {
			$this->redirect('this');
		}
		$eventId = (int) $eventIdParam;

		$identity = $this->user->getIdentity();
		if (!$identity instanceof SimpleIdentity) {
			$this->redirect('this');
		}

		$userId = is_int($identity->id) ? $identity->id : 0;
		$userRole = is_string($identity->role) ? $identity->role : null;

		$event = $this->eventFacade->getEventById($eventId);
		$isOrganizer = $event instanceof ActiveRow && $event->organizer_id === $userId;

		if (
			!in_array($userRole, ['admin', 'moderator'], true)
			&& !$isOrganizer
			&& !$this->eventFacade->isUserJoined($eventId, $userId)
		) {
			$this->flashMessage('Pouze účastníci mohou psát do chatu.', 'danger');
			$this->redirect('this');
		}

		$content = is_string($values->content) ? $values->content : '';
		if ($content !== '') {
			$this->eventFacade->addChatMessage($eventId, $userId, $content);
			$this->flashMessage('Zpráva byla odeslána.', 'success');
		}
		
		$this->redirect('this');
	}

	public function handleDeleteMessage(int $messageId): void
	{
		$identity = $this->user->getIdentity();
		$role = $identity instanceof SimpleIdentity && is_string($identity->role) ? $identity->role : null;

		if (!$this->user->isLoggedIn() || !in_array($role, ['admin', 'moderator'], true)) {
			$this->flashMessage('Pouze administrátor nebo moderátor může mazat zprávy.', 'danger');
			$this->redirect('this');
		}
		$this->eventFacade->deleteChatMessage($messageId);
		$this->flashMessage('Zpráva byla smazána.', 'info');
		$this->redirect('this');
	}

	// --- УДАЛЕНИЕ СОБЫТИЯ ---

	public function handleDeleteEvent(int $id): void
	{
		$identity = $this->user->getIdentity();
		$role = $identity instanceof SimpleIdentity && is_string($identity->role) ? $identity->role : null;

		if (!$this->user->isLoggedIn() || !in_array($role, ['admin', 'moderator'], true)) {
			$this->flashMessage('Pouze administrátor nebo moderátor může mazat události.', 'danger');
			$this->redirect('this');
		}
		$this->eventFacade->deleteAllChatMessagesForEvent($id);
		$this->eventFacade->deleteEvent($id);
		$this->flashMessage('Událost a její chat byly smazány.', 'info');
		$this->redirect(':Front:Home:default');
	}

	public function handleUnblock(int $userId): void
	{
		$eventIdParam = $this->getParameter('id');
		if (!is_numeric($eventIdParam)) {
			$this->redirect('this');
		}
		$eventId = (int) $eventIdParam;
		
		$this->checkManagementPermissions($eventId);

		$this->eventFacade->unblockUser($eventId, $userId);
		
		$this->flashMessage('Uživatel byl odblokován.', 'success');
		$this->redirect('this');
	}
}
