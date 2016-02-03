<?php
/**
 * OreDict API class
 *
 * @author Noah Manneschmidt <nmanneschmidt@curse.com>
 */

/**
 * Actions available:
 *  1. get a list of entries with filtering (by tag, prefix, or mod)
 *  2. add entries
 *  3. delete entries
 *  4. edit entries
 */
class OreDictApi extends ApiBase {
	public function getDescription() {
		return 'Provides an API to query and modify entries of the OreDict extension.';
	}

	/**
	 * Returns example usage url
	 */
	public function getExamples() {
		return [
			'api.php?action=oredict&oredict-search-tag=logWood',
			'api.php?action=oredict&oredict-search-tag=logWood&oredict-search-mod=V',
			'api.php?action=oredict&oredict-add=<newentry1>|<newentry2>|<newentry3>&token=<edittoken>',
			'api.php?action=oredict&oredict-del=1|2|3|4&token=<edittoken>',
		];
	}

	/**
	 * Returns the descriptions of the various parameters that are supported by this API
	 *
	 * @return	array	param names as keys, english descriptions as values
	 */
	protected function getParamDescription() {
		return [
			'token' => 'The edit token for the current user. Required for write actions.',
			'oredict-add' => [
				'Provides data for new entries to be created. Multiple entries are separated by a pipe character.',
				'The data format is TBD, but likely to be comma separated fields matching the DB layout.'
			],
			'oredict-edit' => [
				'Provides new data for existing entries to be updated. Multiple entries are separated by a pipe character.',
				'The data format is TBD, but will resemble the format used for oredict-add with the addition of an ID number.'
			],
			'oredict-del' => 'Provides IDs of existing entries to be deleted. Multiple entries are separated by a pipe character.',
			'oredict-search-prefix' => 'Limits the search results returned to those having a name with the given prefix.',
			'oredict-search-tag' => 'Limits the search results returned to those having a given tag.',
			'oredict-search-mod' => 'Limits the search results returned to those belonging to a given mod.',
			'oredict-get' => 'Gets the tag, item, mod, grid params, and flags for the provided pipe separated entry IDs',
		];
	}

	/**
	 * Defines names and types of parameters that can be used with this API
	 *
	 * @return	array
	 */
	protected function getAllowedParams() {
		return [
			# required for editing actions
			'token' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'mod' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'tag' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'item' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'params' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'flags' => [
				ApiBase::PARAM_TYPE => 'integer',
			],
			# editing actions
			'oredict-add' => [
				ApiBase::PARAM_TYPE => 'boolean',
			],
			'oredict-edit' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_MIN => 1,
			],
			'oredict-del' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_ISMULTI => true,
				ApiBase::PARAM_ALLOW_DUPLICATES => false,
				ApiBase::PARAM_MIN => 1,
			],
			# searching filters
			'oredict-search-prefix' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'oredict-search-tag' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'oredict-search-mod' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'oredict-search-name' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'oredict-get' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_ISMULTI => true,
				ApiBase::PARAM_ALLOW_DUPLICATES => false,
				ApiBase::PARAM_MIN => 1,
			],
		];
	}

	/**
	 * Returns true when an editing action is being attempted
	 */
	private function isEditAction() {
		$editParams = ['oredict-add', 'oredict-edit', 'oredict-del'];
		foreach ($editParams as $param) {
			$val = $this->getRequest()->getVal($param);
			if (!empty($val)) return true;
		}
		return false;
	}

	/**
	 * Returns true when a searching action is being attempted
	 */
	private function isSearchAction() {
		$searchParams = ['oredict-search-prefix', 'oredict-search-tag', 'oredict-search-mod', 'oredict-search-name'];
		foreach ($searchParams as $param) {
			$val = $this->getRequest()->getVal($param);
			if (!empty($val)) return true;
		}
		return false;
	}

	/**
	 * @return bool		Whether the basic get-by-ID action is being attempted
	 */
	private function isGetByIDAction() {
		$val = $this->getRequest()->getVal('oredict-get');
		if (empty($val)) {
			return false;
		} else {
			return true;
		}
	}

	public function needsToken() {
		return $this->isEditAction() ? '' : false;
	}

	public function mustBePosted() {
		return $this->isEditAction();
	}

	public function getTokenSalt() {
		return $this->needsToken();
	}

	/**
	 * Main execution entry point
	 */
	public function execute() {
		if ($this->isEditAction()) {
			// enforce permissions
			if ($this->getUser()->isAllowed('editoredict')) {
				$this->doDelete();
				$this->doEdit();
				$this->doAdd();
			} else {
				$this->dieUsage(wfMessage('badaccess-groups', 'editoredict', 1)->text(), 'permission_needed');
			}
		}

		if ($this->isSearchAction()) {
			$results = $this->doSearch();
//			$this->getResult()->setIndexedTagName($results, 'entry');
			$this->getResult()->addValue($this->getModuleName(), 'entries', $results);
		}

		if ($this->isGetByIDAction()) {
			$this->doGet();
		}
	}

	/**
	 * Delete the entries given by the IDs in the oredict-del parameter
	 */
	protected function doDelete() {
		$entryIds = $this->getParameter('oredict-del');
		if (empty($entryIds)) {
			return;
		}

		$ret = array();

		foreach ($entryIds as $id) {
			if (OreDict::checkExistsByID($id)) {
				$result = OreDict::deleteEntry($id, $this->getUser());
				$ret[$id] = $result;
			} else {
				$ret[$id] = false;
			}
		}

		$this->getResult()->addValue([$this->getModuleName(), 'actionresult'], 'delete', $ret);
	}

	protected function doEdit() {
		$id = $this->getParameter('oredict-edit');
		if (empty($id)) {
			return;
		}

		if (!OreDict::checkExistsByID($id)) {
			$this->getResult()->addValue([$this->getModuleName(), 'actionresult'], 'edit', array('error' => "Entry $id does not exists."));
			return;
		}

		// These aren't all required, but the edit code will check if they are empty anyway.
		$mod = $this->getParameter('mod');
		$item = $this->getParameter('item');
		$tag = $this->getParameter('tag');
		$params = $this->getParameter('params');
		$flags = $this->getParameter('flags');

		$update = array(
			'mod_name' => $mod,
			'item_name' => $item,
			'tag_name' => $tag,
			'grid_params' => $params,
			'flags' => $flags
		);

		$result = OreDict::editEntry($update, $id, $this->getUser());
		$ret = array();
		switch ($result) {
			case 0: {
				$ret = array($id => true);
				break;
			}
			case 1: {
				$ret = array($id => false, 'error' => 'Failed to edit the database.');
				break;
			}
			case 2: {
				$ret = array($id => false, 'error' => 'There was no change.');
				break;
			}
			default: {
				$ret = array('?' => $result);
			}
		}
		$this->getResult()->addValue([$this->getModuleName(), 'actionresult'], 'edit', $ret);
	}

	protected function doAdd() {
		$newEntries = $this->getParameter('oredict-add');
		if (empty($newEntries)) {
			return;
		}
		$mod = $this->getParameter('mod');
		$item = $this->getParameter('item');
		$tag = $this->getParameter('tag');
		$params = empty($this->getParameter('params')) ? '' : $this->getParameter('params');
		$flags = empty($this->getParameter('flags')) ? OreDict::FLAG_DEFAULT : $this->getParameter('flags');

		if (empty($mod) || empty($item) || empty($tag)) {
			$this->getResult()->addValue([$this->getModuleName(), 'actionresult'], 'add', array('error' => 'Mod, item, and tag must be provided.'));
		} else {
			if (OreDict::checkExists($item, $tag, $mod)) {
				$result = OreDict::addEntry($mod, $item, $tag, $this->getUser(), $params, $flags);
				$ret = array('result' => $result);
				$this->getResult()->addValue([$this->getModuleName(), 'actionresult'], 'add', $ret);
			} else {
				$this->getResult()->addValue([$this->getModuleName(), 'actionresult'], 'add', array('error' => 'Entry already exists.'));
			}
		}
	}

	/**
	 * @param $row ?        The row to get the data from.
	 * @return array		An array containing the tag, mod, item, grid params, and flags for use throughout the API.
	 */
	private function getArrayFromRow($row) {
		return array(
			'tag_name' => $row->tag_name,
			'mod_name' => $row->mod_name,
			'item_name' => $row->item_name,
			'grid_params' => $row->grid_params,
			'flags' => $row->flags,
		);
	}

	protected function doGet() {
		$ids = $this->getParameter('oredict-get');
		if (empty($ids)) {
			return;
		}
		$dbr = wfGetDB(DB_SLAVE);

		$ret = array();

		foreach ($ids as $id) {
			$results = $dbr->select('ext_oredict_items', '*', array('entry_id' => $id));
			if ($results->numRows() > 0) {
				$row = $results->current();
				$ret[$id] = $this->getArrayFromRow($row);
			} else {
				$ret[$id] = null;
			}
		}

		$this->getResult()->addValue([$this->getModuleName(), 'actionresult'], 'get', $ret);
	}

	protected function doSearch() {
		$prefix = $this->getMain()->getVal('oredict-search-prefix');
		$tag = $this->getMain()->getVal('oredict-search-tag');
		$mod = $this->getMain()->getVal('oredict-search-mod');
		$name = $this->getMain()->getVal('oredict-search-name');
		$dbr = wfGetDB(DB_SLAVE);

		$resultPrefix = $dbr->select('ext_oredict_items', '*', array('item_name BETWEEN '.$dbr->addQuotes($prefix)." AND 'zzzzzzzz'"));
		$resultTag = $dbr->select('ext_oredict_items', '*', array('tag_name' => $tag));
		$resultMod = $dbr->select('ext_oredict_items', '*', array('mod_name' => $mod));
		$resultName = $dbr->select('ext_oredict_items', '*', array('item_name' => $name));

		$ret = array();

		if ($resultTag->numRows() > 0) {
			foreach ($resultTag as $row) {
				$ret[$row->entry_id] = $this->getArrayFromRow($row);
			}
		}

		if ($resultMod->numRows() > 0) {
			foreach ($resultMod as $row) {
				$ret[$row->entry_id] = $this->getArrayFromRow($row);
			}
		}

		if ($resultName->numRows() > 0) {
			foreach ($resultName as $row) {
				$ret[$row->entry_id] = $this->getArrayFromRow($row);
			}
		}

		if ($resultPrefix->numRows() > 0) {
			foreach ($resultPrefix as $row) {
				$ret[$row->entry_id] = $this->getArrayFromRow($row);
			}
		}

		return $ret;
	}
}
