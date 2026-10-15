<?php

declare(strict_types=1);

namespace App\Model;

use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Row;

final class EventFacade
{
	private Explorer $db;

	public function __construct(Explorer $db)
	{
		$this->db = $db;
	}

	// --- GETTERS (ЧТЕНИЕ) ---

	/**
	 * Для Фронтенда: Пагинация и фильтрация
	 * @return array<int, ActiveRow>
	 */
	public function getUpcomingEventsPaginated(
		int $limit = 9,
		int $offset = 0,
		string $keyword = '',
		?int $gameId = null
	): array {
		$query = $this->db->table('events')
			->where('start_time >= ?', new \DateTime())
			->where(':event_moderation.status', 'approved');

		if ($keyword) {
			$query->where(
				'title LIKE ? OR location LIKE ? OR description LIKE ?',
				"%$keyword%",
				"%$keyword%",
				"%$keyword%"
			);
		}

		if ($gameId) {
			$query->where('game_id = ?', $gameId);
		}

		// Безопасные лимиты для PHPStan 10 (int<0, max>)
		$safeLimit = $limit < 0 ? 0 : $limit;
		$safeOffset = $offset < 0 ? 0 : $offset;

		/** @var array<int, ActiveRow> $result */
		$result = $query->limit($safeLimit, $safeOffset)->fetchAll();
		return $result;
	}

	/**
	 * Для Фронтенда: Все будущие одобренные события
	 * @return array<int, ActiveRow>
	 */
	public function getUpcomingEvents(): array
	{
		/** @var array<int, ActiveRow> $result */
		$result = $this->db->table('events')
			->where('start_time >= ?', new \DateTime())
			->where(':event_moderation.status', 'approved')
			->order('start_time ASC')
			->fetchAll();
		return $result;
	}

	// Подсчет для пагинации
	public function countUpcomingEvents(string $keyword = '', ?int $gameId = null): int
	{
		$query = $this->db->table('events')
			->where('start_time >= ?', new \DateTime())
			->where(':event_moderation.status', 'approved');

		if ($keyword) {
			$query->where(
				'title LIKE ? OR location LIKE ? OR description LIKE ?',
				"%$keyword%",
				"%$keyword%",
				"%$keyword%"
			);
		}

		if ($gameId) {
			$query->where('game_id = ?', $gameId);
		}

		return $query->count('*');
	}

	/**
	 * Для Админки: Все события (без фильтрации по статусу)
	 * @return array<int, ActiveRow>
	 */
	public function getAllEvents(): array
	{
		/** @var array<int, ActiveRow> $result */
		$result = $this->db->table('events')
			->order('start_time ASC')
			->fetchAll();
		return $result;
	}

	/**
	 * Для Модератора: Только ожидающие (pending)
	 * @return array<int, ActiveRow>
	 */
	public function getPendingEventsForModeration(): array
	{
		/** @var array<int, ActiveRow> $result */
		$result = $this->db->table('events')
			->where(':event_moderation.status', 'pending')
			->order(':event_moderation.updated_at ASC')
			->fetchAll();
		return $result;
	}

	/**
	 * Ожидающие события конкретного организатора
	 * @return array<int, ActiveRow>
	 */
	public function getPendingEventsByOrganizer(int $organizerId): array
	{
		/** @var array<int, ActiveRow> $result */
		$result = $this->db->table('events')
			->where('organizer_id', $organizerId)
			->where(':event_moderation.status', 'pending')
			->order(':event_moderation.updated_at ASC')
			->fetchAll();
		return $result;
	}
	
	/**
	 * Получение одного события по ID
	 */
	public function getEventById(int $id): ?ActiveRow
	{
		return $this->db->table('events')->get($id);
	}
	
	// Вспомогательный метод: данные модерации
	public function getModerationData(int $eventId): ?ActiveRow
	{
		return $this->db->table('event_moderation')->where('event_id', $eventId)->fetch();
	}

	/**
	 * События конкретного организатора (будущие)
	 * @return array<int, ActiveRow>
	 */
	public function getUpcomingEventsByOrganizer(int $organizerId): array
	{
		/** @var array<int, ActiveRow> $result */
		$result = $this->db->table('events')
			->where('organizer_id', $organizerId)
			->where('start_time >= ?', new \DateTime())
			->order('start_time ASC')
			->fetchAll();
		return $result;
	}

	/**
	 * События конкретного организатора (прошедшие)
	 * @return array<int, ActiveRow>
	 */
	public function getPastEventsByOrganizer(int $organizerId): array
	{
		/** @var array<int, ActiveRow> $result */
		$result = $this->db->table('events')
			->where('organizer_id', $organizerId)
			->where('start_time < ?', new \DateTime())
			->order('start_time DESC')
			->fetchAll();
		return $result;
	}


	// --- УПРАВЛЕНИЕ СОБЫТИЯМИ (ACTIONS) ---

	/**
	 * Создание события
	 * @param array<string, mixed> $data
	 */
	public function createEvent(array $data, int $authorId): void
	{
		$this->db->beginTransaction();
		try {
			$gameIdParam = $data['game_id'] ?? null;
			$gameId = is_numeric($gameIdParam) ? (int)$gameIdParam : null;

			// 1. Вставка события
			$eventRow = $this->db->table('events')->insert([
				'title' => $data['title'],
				'location' => $data['location'],
				'start_time' => $data['start_time'],
				'end_time' => $data['end_time'] ?? null,
				'description' => $data['description'],
				'status' => $data['status'] ?? 'open',
				'organizer_id' => $authorId,
				'game_id' => $gameId,
			]);

			if (!$eventRow instanceof ActiveRow) {
				throw new \Exception('Chyba při vytváření události.');
			}

			// 2. Вставка записи о модерации
			$this->db->table('event_moderation')->insert([
				'event_id' => $eventRow->id,
				'status' => 'pending',
				'author_id' => $authorId,
				'moderator_id' => null,
				'updated_at' => new \DateTime(),
			]);

			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollback();
			throw $e;
		}
	}

	/**
	 * Обновление события
	 * @param array<string, mixed> $data
	 */
	public function updateEvent(int $id, array $data, int $authorId): void
	{
		$this->db->beginTransaction();
		try {
			// 1. Получаем текущие данные события для сравнения
			$currentEvent = $this->db->table('events')->get($id);
			if (!$currentEvent) {
				throw new \Exception("Event not found");
			}

			// 2. Проверяем, изменились ли ВАЖНЫЕ поля (требующие модерации)
			$needsModeration = false;
			$criticalFields = ['title', 'location', 'description', 'game_id', 'start_time', 'end_time'];

			foreach ($criticalFields as $field) {
				if (!array_key_exists($field, $data)) {
					continue;
				}

				$oldValue = $currentEvent->$field;
				$newValue = $data[$field];

				if (in_array($field, ['start_time', 'end_time'])) {
					$oldStr = $oldValue instanceof \DateTimeInterface ? $oldValue->format('Y-m-d H:i') : null;
					
					$newStr = null;
					if (!empty($newValue)) {
						try {
							$dateVal = is_string($newValue) ? $newValue : 'now';
							$dateObj = ($newValue instanceof \DateTimeInterface) ? $newValue : new \DateTime($dateVal);
							$newStr = $dateObj->format('Y-m-d H:i');
						} catch (\Exception $e) {
							$newStr = is_string($newValue) ? $newValue : null;
						}
					}

					if ($oldStr !== $newStr) {
						$needsModeration = true;
						break;
					}
					continue;
				}

				if ($oldValue != $newValue) {
					$needsModeration = true;
					break;
				}
			}

			// 3. Обновляем само событие в БД
			$updateData = [
				'title' => $data['title'] ?? $currentEvent->title,
				'location' => $data['location'] ?? $currentEvent->location,
				'start_time' => $data['start_time'] ?? $currentEvent->start_time,
				'end_time' => $data['end_time'] ?? $currentEvent->end_time,
				'description' => $data['description'] ?? $currentEvent->description,
			];
			
			if (array_key_exists('game_id', $data)) {
				$gameIdParam = $data['game_id'];
				$updateData['game_id'] = is_numeric($gameIdParam) ? (int)$gameIdParam : null;
			}

			if (isset($data['status'])) {
				$updateData['status'] = $data['status'];
			}

			$this->db->table('events')->where('id', $id)->update($updateData);

			// 4. Сбрасываем модерацию ТОЛЬКО если изменились важные поля
			if ($needsModeration) {
				$modData = [
					'status' => 'pending',
					'author_id' => $authorId,
					'moderator_id' => null,
					'updated_at' => new \DateTime(),
				];

				$existingMod = $this->db->table('event_moderation')->where('event_id', $id)->fetch();
				
				if ($existingMod) {
					$existingMod->update($modData);
				} else {
					$modData['event_id'] = $id;
					$this->db->table('event_moderation')->insert($modData);
				}
			}

			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollback();
			throw $e;
		}
	}

	// Решение модератора (Approved/Rejected)
	public function moderateEvent(int $eventId, string $status, int $moderatorId): void
	{
		if (!in_array($status, ['approved', 'rejected'])) {
			throw new \InvalidArgumentException("Invalid status");
		}

		$this->db->table('event_moderation')
			->where('event_id', $eventId)
			->update([
				'status' => $status,
				'moderator_id' => $moderatorId,
				'updated_at' => new \DateTime(),
			]);
	}

	// Удаление события
	public function deleteEvent(int $id): void
	{
		$this->db->table('events')->where('id', $id)->delete();
	}


	// --- ИГРЫ ---

	/**
	 * @return array<int, string>
	 */
	public function getAllGames(): array
	{
		/** @var array<int, string> $result */
		$result = $this->db->table('games')->fetchPairs('id', 'name');
		return $result;
	}

	public function createGame(string $name): int
	{
		$existing = $this->db->table('games')->where('name', $name)->fetch();
		if ($existing) {
			$id = $existing->id;
			return is_numeric($id) ? (int)$id : 0;
		}
		
		$row = $this->db->table('games')->insert(['name' => $name]);
		if ($row instanceof ActiveRow) {
			$id = $row->id;
			return is_numeric($id) ? (int)$id : 0;
		}
		
		return 0;
	}


	// --- УЧАСТНИКИ И БЛОКИРОВКИ (НОВЫЕ МЕТОДЫ) ---

	/**
	 * Получить список всех участников
	 * @return array<int, ActiveRow>
	 */
	public function getParticipants(int $eventId): array
	{
		/** @var array<int, ActiveRow> $result */
		$result = $this->db->table('event_participants')
			->where('event_id', $eventId)
			->order('joined_at ASC')
			->fetchAll();
		return $result;
	}

	// Проверка, записан ли уже пользователь
	public function isUserJoined(int $eventId, int $userId): bool
	{
		return (bool) $this->db->table('event_participants')
			->where('event_id', $eventId)
			->where('user_id', $userId)
			->count('*');
	}

	// Присоединиться к событию (с проверкой блокировки)
	public function joinEvent(int $eventId, int $userId): void
	{
		if ($this->isUserBlocked($eventId, $userId)) {
			throw new \Exception("Uživatel je zablokován a nemůže se připojit.");
		}

		$this->db->table('event_participants')->insert([
			'event_id' => $eventId,
			'user_id' => $userId,
			'joined_at' => new \DateTime()
		]);
	}

	// Покинуть событие / Удалить участника (Kick)
	public function leaveEvent(int $eventId, int $userId): void
	{
		$this->db->table('event_participants')
			->where('event_id', $eventId)
			->where('user_id', $userId)
			->delete();
	}

	// Заблокировать пользователя для события (Ban)
	public function blockUser(int $eventId, int $userId): void
	{
		try {
			$this->db->table('event_blocked_users')->insert([
				'event_id' => $eventId,
				'user_id' => $userId,
				'blocked_at' => new \DateTime(),
			]);
		} catch (\Nette\Database\UniqueConstraintViolationException $e) {
			// Если уже заблокирован — ничего не делаем
		}
	}

	// Проверка статуса блокировки
	public function isUserBlocked(int $eventId, int $userId): bool
	{
		return (bool) $this->db->table('event_blocked_users')
			->where('event_id', $eventId)
			->where('user_id', $userId)
			->count('*');
	}


	// --- ЧАТ ---

	/**
	 * @return array<int, Row>
	 */
	public function getChatMessages(int $eventId): array
	{
		// Join users and roles for nice display
		/** @var array<int, Row> $result */
		$result = $this->db->query('
			SELECT c.*, u.username, r.name AS role 
			FROM comments c 
			JOIN users u ON c.user_id = u.id 
			JOIN roles r ON u.role_id = r.id 
			WHERE c.event_id = ? 
			ORDER BY c.created_at ASC
		', $eventId)->fetchAll();
		
		return $result;
	}

	public function addChatMessage(int $eventId, int $userId, string $content): void
	{
		$this->db->table('comments')->insert([
			'event_id' => $eventId,
			'user_id' => $userId,
			'content' => $content,
			'created_at' => new \DateTime()
		]);
	}

	public function deleteChatMessage(int $messageId): void
	{
		$this->db->table('comments')->where('id', $messageId)->delete();
	}

	public function deleteAllChatMessagesForEvent(int $eventId): void
	{
		$this->db->table('comments')->where('event_id', $eventId)->delete();
	}

	/**
	 * @return array<int, ActiveRow>
	 */
	public function getBlockedUsers(int $eventId): array
	{
		// Получаем записи из таблицы блокировок
		/** @var array<int, ActiveRow> $result */
		$result = $this->db->table('event_blocked_users')
			->where('event_id', $eventId)
			->order('blocked_at DESC')
			->fetchAll();
		return $result;
	}

	public function unblockUser(int $eventId, int $userId): void
	{
		$this->db->table('event_blocked_users')
			->where('event_id', $eventId)
			->where('user_id', $userId)
			->delete();
	}

	/**
	 * @return array<int, ActiveRow>
	 */
	public function getGamesList(): array
	{
		/** @var array<int, ActiveRow> $result */
		$result = $this->db->table('games')->fetchAll();
		return $result;
	}

	public function getGameById(int $id): ?ActiveRow
	{
		return $this->db->table('games')->get($id);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function updateGame(int $id, array $data): void
	{
		$this->db->table('games')->where('id', $id)->update($data);
	}

	public function deleteGame(int $id): void
	{
		$this->db->table('games')->where('id', $id)->delete();
	}

	/**
	 * Поиск событий по заголовку или описанию
	 * @return \Nette\Database\Table\Selection<\Nette\Database\Table\ActiveRow>
	 */
	public function search(string $query): \Nette\Database\Table\Selection
	{
		return $this->db->table('events')
			->where('title LIKE ? OR description LIKE ?', "%$query%", "%$query%")
			->where('status', 'approved')
			->order('start_time DESC');
	}
}
