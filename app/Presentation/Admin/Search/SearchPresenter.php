<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Search;

use Nette;
use Nette\Application\UI\Form;
use App\Model\UserFacade;
use App\Model\EventFacade;
use Nette\Utils\ArrayHash;

final class SearchPresenter extends Nette\Application\UI\Presenter
{
	/**
	 * Конструктор: внедряем оба фасада с ключевым словом private.
	 * Это автоматически создает свойства $this->userFacade и $this->eventFacade.
	 */
	public function __construct(
		private UserFacade $userFacade,
		private EventFacade $eventFacade
	) {
		parent::__construct();
	}

	public function startup(): void
	{
		parent::startup();
		// Проверка прав: только Админ и Модератор
		if (!$this->getUser()->isInRole('admin') && !$this->getUser()->isInRole('moderator')) {
			$this->flashMessage('Přístup odepřen.', 'danger');
			$this->redirect(':Front:Home:default');
		}
	}

	protected function createComponentSearchForm(): Form
	{
		$form = new Form;
		
		// Поле запроса 'q'
		$form->addText('q', 'Hledat:')
			->setRequired('Zadejte vyhledávací dotaz.');

		$form->addSubmit('send', 'Hledat');

		/** * Используем метод GET.
		 * Это позволит пользователям делиться ссылкой на результаты поиска.
		 */
		$form->setMethod('get');

		$form->onSuccess[] = [$this, 'searchFormSucceeded'];
		return $form;
	}

	/**
	 * Обработка успешной отправки формы.
	 * Убрали тип mixed из сигнатуры, чтобы не было ошибки ReflectionException.
	 * * @param Form $form
	 * @param mixed $values
	 */
	public function searchFormSucceeded(Form $form, $values): void
	{
		if (!$values instanceof ArrayHash) {
			return;
		}

		// Получаем значение 'q' (как назвали в addText)
		$query = is_string($values->q) ? trim($values->q) : '';

		if ($query === '') {
			$this->redirect('default');
		}

		// Перенаправляем на renderResults с параметром q
		$this->redirect('results', ['q' => $query]);
	}

	/**
	 * Отображение результатов поиска
	 */
	public function renderResults(string $q): void
	{
		$this->template->q = $q;
		$this->template->keyword = $q;

	// Получаем данные
		$userResults = $this->userFacade->search($q);
		$eventResults = $this->eventFacade->search($q);

	// Раскладываем по переменным
		$this->template->userResults = $userResults;
		$this->template->eventResults = $eventResults;

	// ВАЖНО: так как твоя таблица в шаблоне ждет пользователей,
	// отдаем их в основную переменную $results
		$this->template->results = $userResults;
	
	// Посчитаем общее количество для футера (Nalezeno záznamů)
		$this->template->count = $userResults->count();
	}
}
