<?php
/******************************************************************************
 *
 * Subrion - open source content management system
 * Copyright (C) 2017 Intelliants, LLC <https://intelliants.com>
 *
 * This file is part of Subrion.
 *
 * Subrion is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Subrion is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Subrion. If not, see <http://www.gnu.org/licenses/>.
 *
 *
 * @link https://subrion.org/
 *
 ******************************************************************************/

class iaItem extends abstractCore
{
	const TYPE_PACKAGE = 'package';
	const TYPE_PLUGIN = 'plugin';

	protected static $_table = 'items';
	protected static $_favoritesTable = 'favorites';
	protected static $_modulesTable = 'modules';

	private $_itemTools;


	public static function getFavoritesTable()
	{
		return self::$_favoritesTable;
	}

	public static function getModulesTable()
	{
		return self::$_modulesTable;
	}

	public function getFavoritesByMemberId($memberId)
	{
		$stmt = "`item` IN (':items') AND `member_id` = :user";
		$stmt = iaDb::printf($stmt, ['items' => implode("','", $this->getItems()), 'user' => (int)$memberId]);

		$result = [];

		if ($rows = $this->iaDb->all(['item', 'id'], $stmt, null, null, self::getFavoritesTable()))
		{
			foreach ($rows as $row)
			{
				$key = $row['item'];
				isset($result[$key]) || $result[$key] = [];

				$result[$key][] = $row['id'];
			}
		}

		return $result;
	}

	/**
	* Returns array with keys of available items and values - packages titles
	*
	* @param bool $payableOnly - flag to return items, that can be paid
	*
	* @return array
	*/
	public function getPackageItems($payableOnly = false)
	{
		$result = [];

		$itemsInfo = $this->getItemsInfo($payableOnly);
		foreach ($itemsInfo as $itemInfo)
		{
			$result[$itemInfo['item']] = $itemInfo['module'];
		}

		return $result;
	}

	/**
	 * Returns items list
	 *
	 * @param bool $payableOnly - flag to return items, that can be paid
	 *
	 * @return array
	 */
	public function getItemsInfo($payableOnly = false)
	{
		static $itemsInfo;

		if (!isset($itemsInfo[(int)$payableOnly]))
		{
			$items = $this->iaDb->all('`item`, `module`, IF(`table_name` != \'\', `table_name`, `item`) `table_name`', $payableOnly ? '`payable` = 1' : '', null, null, self::getTable());
			$itemsInfo[(int)$payableOnly] = is_array($items) ? $items : [];

			// get active packages
			$packages = $this->iaDb->onefield('name', "`type` = 'package' AND `status` = 'active'", null, null, self::getModulesTable());

			foreach ($items as $key => $itemInfo)
			{
				if (iaCore::CORE != $itemInfo['module'] && !in_array($itemInfo['module'], $packages))
				{
					unset($itemsInfo[(int)$payableOnly][$key]);
				}
			}
		}

		return $itemsInfo[(int)$payableOnly];
	}

	/**
	 * Returns list of items
	 *
	 * @param bool $payableOnly - flag to return items, that can be paid
	 *
	 * @return array
	 */
	public function getItems($payableOnly = false)
	{
		return array_keys($this->getPackageItems($payableOnly));
	}

	protected function _searchItems($search, $type = 'item')
	{
		$items = $this->getPackageItems();
		$result = [];

		foreach ($items as $item => $package)
		{
			if ($search == $$type)
			{
				if ($type == 'item')
				{
					return $package;
				}
				else
				{
					$result[] = $item;
				}
			}
		}

		return ($type == 'item') ? false : $result;
	}

	/**
	 * Returns list of items by package name
	 * @alias _searchItems
	 * @param string $packageName
	 * @return array
	 */
	public function getItemsByPackage($packageName)
	{
		return $this->_searchItems($packageName, 'package');
	}

	/**
	 * Returns package name by item name
	 * @alias _searchItems
	 * @param $search
	 * @return string|bool
	 */
	public function getPackageByItem($search)
	{
		return $this->_searchItems($search, 'item');
	}

	/**
	 * Returns item table name
	 *
	 * @param $item item name
	 *
	 * @return string
	 */
	public function getItemTable($item)
	{
		$result = $this->iaDb->one_bind('table_name', '`item` = :item', ['item' => $item], self::getTable());
		$result || $result = $item;

		return $result;
	}

	/**
	 * Returns an array of enabled items for specified plugin
	 * @param $plugin
	 * @return array
	 */
	public function getEnabledItemsForPlugin($plugin)
	{
		$result = [];
		if ($plugin)
		{
			$items = $this->iaCore->get($plugin . '_items_enabled');
			if ($items)
			{
				$result = explode(',', $items);
			}
		}

		return $result;
	}

	/**
	 * Set items for specified plugin
	 *
	 * @param string $plugin plugin name
	 * @param array $items items list
	 */
	public function setEnabledItemsForPlugin($plugin, $items)
	{
		if ($plugin)
		{
			$this->iaView->set($plugin . '_items_enabled', implode(',', $items), true);
		}
	}

	/**
	 * Return list of items with favorites field
	 *
	 * @param array $listings listings to be processed
	 * @param $itemName item name
	 *
	 * @return mixed
	 */
	public function updateItemsFavorites($listings, $itemName)
	{
		if (empty($itemName))
		{
			return $listings;
		}

		if (!iaUsers::hasIdentity())
		{
			if (isset($_SESSION[iaUsers::SESSION_FAVORITES_KEY][$itemName]['items']))
			{
				$itemsFavorites = array_keys($_SESSION[iaUsers::SESSION_FAVORITES_KEY][$itemName]['items']);
			}
		}
		else
		{
			$itemsList = [];
			foreach ($listings as $entry)
			{
				if (
					('members' == $itemName && $entry['id'] != iaUsers::getIdentity()->id) ||
					(isset($entry['member_id']) && $entry['member_id'] != iaUsers::getIdentity()->id)
				)
				{
					$itemsList[] = $entry['id'];
				}
			}

			if (empty($itemsList))
			{
				return $listings;
			}

			// get favorites
			$itemsFavorites = $this->iaDb->onefield('`id`', "`id` IN ('" . implode("','", $itemsList) . "') && `item` = '{$itemName}' && `member_id` = " . iaUsers::getIdentity()->id, 0, null, $this->getFavoritesTable());
		}

		if (empty($itemsFavorites))
		{
			return $listings;
		}

		// process listing and set flag is in favorites array
		foreach ($listings as &$listing)
		{
			$listing['favorite'] = (int)in_array($listing['id'], $itemsFavorites);
		}

		return $listings;
	}

	public function isModuleExist($moduleName, $type = null)
	{
		$stmt = iaDb::printf("`name` = ':name' AND `status` = ':status'", [
			'name' => $moduleName,
			'status' => iaCore::STATUS_ACTIVE
		]);

		if ($type)
		{
			$stmt .= iaDb::printf(" AND `type` = ':type'", ['type' => $type]);
		}

		return (bool)$this->iaDb->exists($stmt, null, self::getModulesTable());
	}

	public function setItemTools($params = null)
	{
		if (is_null($params))
		{
			return $this->_itemTools;
		}

		if (isset($params['id']) && $params['id'])
		{
			$this->_itemTools[$params['id']] = $params;
		}
		else
		{
			$this->_itemTools[] = $params;
		}
	}
}