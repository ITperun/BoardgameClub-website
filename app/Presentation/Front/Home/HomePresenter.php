<?php

declare(strict_types=1);

namespace App\Presentation\Front\Home;

use Nette\Application\UI\Presenter;
use Nette\Application\UI\Form;
use App\Model\EventFacade;
use Nette\Utils\ArrayHash;

final class HomePresenter extends Presenter
{
	private EventFacade $eventFacade;

	public function __construct(EventFacade $eventFacade)
	{
		parent::__construct();
		$this->eventFacade = $eventFacade;
	}

	public function renderDefault(int $page = 1, string $keyword = '', ?int $game = null): void
	{
		$itemsPerPage = 9;

		$games = $this->eventFacade->getAllGames(); // id => name

		$eventsCount = $this->eventFacade->countUpcomingEvents($keyword, $game);
		$offset = ($page - 1) * $itemsPerPage;
		$events = $this->eventFacade->getUpcomingEventsPaginated($itemsPerPage, $offset, $keyword, $game);

		$this->template->title = 'Klub deskových her';
		$this->template->events = $events;
		$this->template->currentPage = $page;
		$this->template->totalPages = (int) ceil($eventsCount / $itemsPerPage);
		$this->template->eventsCount = $eventsCount;
		$this->template->keyword = $keyword;
		$this->template->game = $game;
		$this->template->games = $games;
	}

	protected function createComponentSearchForm(): Form
	{
		$form = new Form;
		$games = $this->eventFacade->getAllGames();

		$form->addText('keyword', 'Hledat:')
			->setHtmlAttribute('placeholder', 'Zadejte název, místo nebo popis...');
		$form->addSelect('game', 'Hra:', $games)
			->setPrompt('Všechny hry');
		$form->addSubmit('search', 'Hledat')
			->setHtmlAttribute('class', 'btn btn-primary');

		$form->onSuccess[] = [$this, 'searchFormSucceeded'];

		$defaults = [];
		$keywordParam = $this->getParameter('keyword');
		if (is_string($keywordParam)) {
			$defaults['keyword'] = $keywordParam;
		}

		$gameParam = $this->getParameter('game');
		if (is_numeric($gameParam)) {
			$defaults['game'] = (int) $gameParam;
		}

		if ($defaults !== []) {
			$form->setDefaults($defaults);
		}

		return $form;
	}

	/**
	 * Исправлено: mixed перенесен в PHPDoc, чтобы избежать ReflectionException
	 * @param Form $form
	 * @param mixed $values
	 */
	public function searchFormSucceeded(Form $form, $values): void
	{
		if (!$values instanceof ArrayHash) {
			return;
		}

		// Явно проверяем типы свойств ArrayHash для PHPStan 10
		$keywordValue = $values->keyword;
		$keyword = is_string($keywordValue) ? trim($keywordValue) : '';

		$gameValue = $values->game;
		$game = is_numeric($gameValue) ? (int) $gameValue : null;

		$this->redirect('default', [
			'keyword' => $keyword,
			'game' => $game,
			'page' => 1,
		]);
	}
}
