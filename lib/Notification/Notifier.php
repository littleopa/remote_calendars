<?php
declare(strict_types=1);
namespace OCA\RemoteCalendars\Notification;

use OCA\RemoteCalendars\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	public function __construct(private IFactory $l10nFactory, private IURLGenerator $url) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Remote calendars');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$name = (string)($notification->getSubjectParameters()['name'] ?? '');
		switch ($notification->getSubject()) {
			case 'auth_failed':
				$notification->setParsedSubject($l->t('Remote calendar "%s": login failed', [$name]))
					->setParsedMessage($l->t('The password was probably changed. Please enter the new password in the settings – until then the calendar is not synchronised.'));
				break;
			case 'sync_failed':
				$notification->setParsedSubject($l->t('Remote calendar "%s" has not been synchronised for hours', [$name]))
					->setParsedMessage($l->t('Please check the status in the settings.'));
				break;
			default:
				throw new UnknownNotificationException();
		}
		$link = $this->url->linkToRouteAbsolute('settings.PersonalSettings.index', ['section' => Application::APP_ID]);
		$notification->setLink($link)
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
		return $notification;
	}
}
