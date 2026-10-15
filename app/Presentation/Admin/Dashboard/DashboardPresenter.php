<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Dashboard;

use App\Presentation\Accessory\RequireLoggedUser;
use Nette;


/**
 * Presenter for the dashboard view.
 * Ensures the user is logged in before access.
 */
final class DashboardPresenter extends Nette\Application\UI\Presenter
{
	// Incorporates methods to check user login status
	use RequireLoggedUser;

	public function startup(): void
	{
		parent::startup();
		// Только Админ и Модератор
		if (!$this->getUser()->isInRole('admin') && !$this->getUser()->isInRole('moderator')) {
			$this->flashMessage('Přístup odepřen.', 'danger');
			$this->redirect(':Front:Home:default');
		}
	}
}
