<?php

declare(strict_types=1);

namespace App\Presentation\Front\Organizer;

use Nette;
use App\Model\EventFacade;
use App\Presentation\Accessory\RequireLoggedUser;

final class OrganizerPresenter extends Nette\Application\UI\Presenter
{
	use RequireLoggedUser;

	public function __construct(
		private EventFacade $eventFacade
	) {
		parent::__construct();
	}

	public function startup(): void
	{
		parent::startup();
		// Доступ только для организаторов (и админов/модераторов, если нужно)
		if (!$this->getUser()->isInRole('organizer') &&
			!$this->getUser()->isInRole('admin') &&
			!$this->getUser()->isInRole('moderator')) {
			$this->flashMessage('Tato sekce je pouze pro organizátory.', 'danger');
			$this->redirect(':Front:Home:default');
		}
	}

	public function renderDefault(): void
	{
		$organizerId = $this->getUser()->getId();

		// Проверка типа для PHPStan Level 10
		if (!is_int($organizerId)) {
			$this->getUser()->logout(true);
			$this->redirect(':Front:Sign:in');
		}
		
		$this->template->upcomingEvents = $this->eventFacade->getUpcomingEventsByOrganizer($organizerId);
		$this->template->pastEvents = $this->eventFacade->getPastEventsByOrganizer($organizerId);
	}
}
