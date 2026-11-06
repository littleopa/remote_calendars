<?php
declare(strict_types=1);
namespace OCA\RemoteCalendars\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ICrypto;

/** Database access for accounts, bindings (remote calendar ↔ local calendar) and object mappings. */
class Store {
	public function __construct(private IDBConnection $db, private ICrypto $crypto) {
	}

	// ---------- accounts ----------
	public function accountsOf(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('extkal_accounts')->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))->orderBy('id');
		return $qb->executeQuery()->fetchAllAssociative();
	}

	public function account(int $id, ?string $uid = null): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('extkal_accounts')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		if ($uid !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
		}
		$row = $qb->executeQuery()->fetchAssociative();
		return $row ?: null;
	}

	public function findAccount(string $uid, string $url, string $username): ?array {
		foreach ($this->accountsOf($uid) as $a) {
			if ($a['url'] === $url && $a['username'] === $username) {
				return $a;
			}
		}
		return null;
	}

	public function createAccount(string $uid, string $url, string $username, string $password): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('extkal_accounts')->values([
			'user_id' => $qb->createNamedParameter($uid),
			'url' => $qb->createNamedParameter($url),
			'username' => $qb->createNamedParameter($username),
			'password' => $qb->createNamedParameter($this->crypto->encrypt($password)),
			'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
		return $qb->getLastInsertId();
	}

	public function setPassword(int $accountId, string $password): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('extkal_accounts')->set('password', $qb->createNamedParameter($this->crypto->encrypt($password)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	/** @return array{0:string,1:string} */
	public function credentials(array $account): array {
		return [$account['username'], $this->crypto->decrypt((string)$account['password'])];
	}

	public function deleteAccount(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('extkal_accounts')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	// ---------- bindings ----------
	public function bindingsOfAccount(int $accountId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('extkal_bindings')->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))->orderBy('id');
		return $qb->executeQuery()->fetchAllAssociative();
	}

	public function allBindings(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('extkal_bindings')->orderBy('id');
		return $qb->executeQuery()->fetchAllAssociative();
	}

	public function binding(int $id, ?string $uid = null): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('extkal_bindings')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		if ($uid !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
		}
		$row = $qb->executeQuery()->fetchAssociative();
		return $row ?: null;
	}

	public function bindingByCalendar(int $calendarId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('extkal_bindings')->where($qb->expr()->eq('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetchAssociative();
		return $row ?: null;
	}

	public function createBinding(int $accountId, string $uid, int $calendarId, string $remoteUrl, string $name, bool $readonly): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('extkal_bindings')->values([
			'account_id' => $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT),
			'user_id' => $qb->createNamedParameter($uid),
			'calendar_id' => $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT),
			'remote_url' => $qb->createNamedParameter($remoteUrl),
			'name' => $qb->createNamedParameter($name),
			'readonly' => $qb->createNamedParameter($readonly ? 1 : 0, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		return $qb->getLastInsertId();
	}

	public function updateBinding(int $id, array $fields): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('extkal_bindings');
		foreach ($fields as $k => $v) {
			$qb->set($k, $qb->createNamedParameter($v, is_int($v) ? IQueryBuilder::PARAM_INT : ($v === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR)));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function deleteBinding(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('extkal_objects')->where($qb->expr()->eq('binding_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('extkal_bindings')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	// ---------- object mappings ----------
	public function mappings(int $bindingId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('extkal_objects')->where($qb->expr()->eq('binding_id', $qb->createNamedParameter($bindingId, IQueryBuilder::PARAM_INT)));
		return $qb->executeQuery()->fetchAllAssociative();
	}

	public function insertMapping(int $bindingId, string $localUri, string $href, string $remoteEtag, string $localEtag): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('extkal_objects')->values([
			'binding_id' => $qb->createNamedParameter($bindingId, IQueryBuilder::PARAM_INT),
			'local_uri' => $qb->createNamedParameter($localUri),
			'remote_href' => $qb->createNamedParameter($href),
			'remote_etag' => $qb->createNamedParameter(mb_substr($remoteEtag, 0, 255)),
			'local_etag' => $qb->createNamedParameter($localEtag),
		])->executeStatement();
	}

	public function updateMapping(int $id, array $fields): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('extkal_objects');
		foreach ($fields as $k => $v) {
			$qb->set($k, $qb->createNamedParameter($k === 'remote_etag' ? mb_substr((string)$v, 0, 255) : $v));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function deleteMapping(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('extkal_objects')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}
}
