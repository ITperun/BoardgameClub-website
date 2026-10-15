<?php

declare(strict_types=1);

namespace App\Model;

use Nette;
use Nette\Security\Passwords;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

/**
 * Фасад для управления пользователями.
 */
final class UserFacade implements Nette\Security\Authenticator
{
	public const TABLE_NAME = 'users';
	public const COLUMN_ID = 'id';
	public const COLUMN_USERNAME = 'username';
	public const COLUMN_PASSWORD = 'password';
	public const COLUMN_EMAIL = 'email';
	public const COLUMN_ROLE = 'role_id';

	private const TABLE_ROLES = 'roles';

	public function __construct(
		private Explorer $database,
		private Passwords $passwords,
	) {
	}

	/**
	 * Находит пользователя по ID.
	 */
	public function getUserById(int $id): ?ActiveRow
	{
		return $this->database->table(self::TABLE_NAME)->get($id);
	}

	/**
	 * Аутентификация пользователя.
	 */
	public function authenticate(string $username, string $password): Nette\Security\IIdentity
	{
		$user = $this->database->table(self::TABLE_NAME)
			->where('username = ? OR email = ?', $username, $username)
			->fetch();

		if (!$user) {
			throw new Nette\Security\AuthenticationException(
				'Uživatel nebyl nalezen.',
				Nette\Security\Authenticator::IdentityNotFound
			);
		}

		$hash = $user[self::COLUMN_PASSWORD];
		if (!is_string($hash) || !$this->passwords->verify($password, $hash)) {
			throw new Nette\Security\AuthenticationException(
				'Neplatné heslo.',
				Nette\Security\Authenticator::InvalidCredential
			);
		}

		if ($this->passwords->needsRehash($hash)) {
			$user->update([
				self::COLUMN_PASSWORD => $this->passwords->hash($password),
			]);
		}

		// Исправление ошибки PHPStan "Cannot cast mixed to int"
		$roleRawValue = $user[self::COLUMN_ROLE];
		$roleId = is_numeric($roleRawValue) ? (int) $roleRawValue : 0;
		$roleName = $this->getRoleNameById($roleId);

		// Создаем Identity со всеми данными, которые нужны в @layout.latte
		return new Nette\Security\SimpleIdentity(
			$user[self::COLUMN_ID],
			$roleName, // Роль для системы безопасности (isInRole)
			[
				'username' => $user[self::COLUMN_USERNAME],
				'email' => $user[self::COLUMN_EMAIL],
				'firstname' => $user['firstname'], // Для отображения имени
				'lastname' => $user['lastname'],   // Для отображения фамилии
				'image' => $user['image'],         // Для аватара
				'role' => $roleName,               // КРИТИЧЕСКИ ВАЖНО для условий в @layout.latte
			]
		);
	}

	/**
	 * Возвращает имя роли по её ID.
	 */
	public function getRoleNameById(int $roleId): string
	{
		$role = $this->database->table(self::TABLE_ROLES)->get($roleId);
		if (!$role) {
			return 'user';
		}

		$name = $role->offsetGet('name');
		return is_string($name) ? $name : 'user';
	}

	/**
	 * Возвращает ID роли по её имени.
	 */
	public function getRoleIdByName(string $name): ?int
	{
		$role = $this->database->table(self::TABLE_ROLES)->where('name', $name)->fetch();
		if (!$role) {
			return null;
		}
		
		$id = $role->offsetGet('id');
		return is_int($id) ? $id : null;
	}

	/**
	 * Возвращает список всех ролей [id => name].
	 * @return array<int, string>
	 */
	public function getAllRoles(): array
	{
		// Используем явную подсказку типа для PHPStan внутри метода
		/** @var array<int, string> $roles */
		$roles = $this->database->table(self::TABLE_ROLES)->fetchPairs('id', 'name');
		return $roles;
	}

	/**
	 * Регистрация нового пользователя.
	 */
	public function add(
		string $username,
		string $email,
		string $password,
		string $role = 'user',
		?string $firstname = null,
		?string $lastname = null,
		?string $birthdate = null,
		?string $phone = null,
		?string $address = null,
		?string $city = null
	): ActiveRow {
		try {
			$roleId = $this->getRoleIdByName($role);

			$data = [
				self::COLUMN_USERNAME => $username,
				self::COLUMN_EMAIL => $email,
				self::COLUMN_PASSWORD => $this->passwords->hash($password),
				self::COLUMN_ROLE => $roleId,
				'firstname' => $firstname,
				'lastname' => $lastname,
				'birthdate' => $birthdate,
				'phone' => $phone,
				'address' => $address,
				'city' => $city,
			];

			$row = $this->database->table(self::TABLE_NAME)->insert($data);
			if (!$row instanceof ActiveRow) {
				throw new \RuntimeException('Chyba při vytváření uživatele.');
			}
			return $row;
		} catch (Nette\Database\UniqueConstraintViolationException) {
			throw new DuplicateNameException();
		}
	}

	/**
	 * Получить пользователя по Email.
	 */
	public function getByEmail(string $email): ?ActiveRow
	{
		return $this->database->table(self::TABLE_NAME)->where(self::COLUMN_EMAIL, $email)->fetch();
	}

	/**
	 * Получить всех пользователей.
	 * @return Selection<ActiveRow>
	 */
	public function getAllUsers(): Selection
	{
		return $this->database->table(self::TABLE_NAME);
	}

	/**
	 * Смена пароля.
	 */
	public function changePassword(int $userId, string $newPassword): void
	{
		$user = $this->getUserById($userId);
		if ($user instanceof ActiveRow) {
			$user->update([
				self::COLUMN_PASSWORD => $this->passwords->hash($newPassword),
			]);
		}
	}

	/**
	 * Обновление данных пользователя.
	 * @param array<mixed, mixed> $data
	 */
	public function edit(int $userId, array $data): void
	{
		$user = $this->getUserById($userId);
		if ($user instanceof ActiveRow) {
			$user->update($data);
		}
	}

	/**
	 * Получить пользователя по логину.
	 */
	public function getByUsername(string $username): ?ActiveRow
	{
		return $this->database->table(self::TABLE_NAME)->where(self::COLUMN_USERNAME, $username)->fetch();
	}

	/**
	 * Проверка пароля.
	 */
	public function verifyPassword(ActiveRow $user, string $password): bool
	{
		$hash = $user->offsetGet(self::COLUMN_PASSWORD);
		return is_string($hash) && $this->passwords->verify($password, $hash);
	}

	/**
	 * Удаление пользователя.
	 */
	public function delete(int $userId): void
	{
		$user = $this->getUserById($userId);
		if ($user instanceof ActiveRow) {
			$user->delete();
		}
	}

	/**
	 * Поиск пользователей.
	 * @return Selection<ActiveRow>
	 */
	public function search(string $keyword): Selection
	{
		return $this->database->table(self::TABLE_NAME)
			->where('username LIKE ? OR email LIKE ?', "%$keyword%", "%$keyword%");
	}
}
