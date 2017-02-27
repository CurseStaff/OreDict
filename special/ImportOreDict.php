<?php
/**
 * ImportOreDict special page file
 *
 * @file
 * @ingroup Extensions
 * @version 1.0.1
 * @author Jinbobo <paullee05149745@gmail.com>
 * @license
 */

class ImportOreDict extends SpecialPage {
	/**
	 * Calls parent constructor and sets special page title
	 */
	public function __construct() {
		parent::__construct('ImportOreDict', 'importoredict');
	}

	/**
	 * Returns the category under which this special page will be grouped on Special:SpecialPages
	 */
	protected function getGroupName() {
		return 'oredict';
	}

	/**
	 * Build special page
	 *
	 * @param null|string $par Subpage name
	 */
	public function execute($par) {
		// Restrict access from unauthorized users
		$this->checkPermissions();

		$out = $this->getOutput();

		$this->setHeaders();
		$this->outputHeader();

		$opts = new FormOptions();

		$opts->add( 'input', 0 );
		$opts->add( 'update_table', 0 );

		$opts->fetchValuesFromRequest( $this->getRequest() );

		$opts->setValue('input', $this->getRequest()->getText('input'));
		$opts->setValue('update_table', intval($this->getRequest()->getText('update_table')));

		// Process and save POST data
		if ($_POST) {
			// XSRF prevention
			if ( !$this->getUser()->matchEditToken( $this->getRequest()->getVal( 'token' ) ) ) {
				return;
			}

			$out->addHtml('<tt>');
			$dbw = wfGetDB(DB_MASTER);

			$input = explode("\n", trim($opts->getValue('input')));

			foreach ($input as $line) {
				// Parse line
				// Line format: OreDict name!item name!mod name!params
				$line = trim($line);
				if ($line == "") {
					continue;
				}
				$p = explode('!', $line);
				foreach ($p as $key => $opt) {
					$p[$key] = trim($opt);
				}
				if (count($p) != 4) {
					$out->addHTML($this->returnMessage(false, wfMessage('oredict-import-fail-format', $line)->text()));
					continue;
				}
				$tagName = $p[0];
				$itemName = $p[1];
				$modName = $p[2];
				$params = $p[3];
				// Check if entry already exist
				if (OreDict::entryExists($itemName, $tagName, $modName)) {
					// If updated mode and entry already exist, update
					if ($opts->getValue('update_table') == 1) {
						$stuff = $dbw->select('ext_oredict_items', '*', array('item_name' => $itemName, 'tag_name' => $tagName, 'mod_name' => $modName));

						$tag = $tagName;
						$fItem = $itemName;
						$mod = $modName;
						$item = $stuff->current();

						// Prepare log vars
						$target = empty($mod) || $mod == "" ? "$tag - $fItem" : "$tag - $fItem ($mod)";

						$diff = array();
						if ($item->item_name != $fItem) {
							$diff['item'][] = $item->item_name;
							$diff['item'][] = $fItem;
						}
						if ($item->mod_name != $mod) {
							$diff['mod'][] = $item->mod_name;
							$diff['mod'][] = $mod;
						}
						if ($item->grid_params != $params) {
							$diff['params'][] = $item->grid_params;
							$diff['params'][] = $params;
						}
						$diffString = "";
						foreach ($diff as $field => $change) {
							$diffString .= "$field [$change[0] -> $change[1]] ";
						}
						if ($diffString == "" || count($diff) == 0) {
							$out->addHTML($this->returnMessage(false, wfMessage('oredict-import-fail-nochange')->text()));
							continue; // No change
						}

						// Start log
						$logEntry = new ManualLogEntry('oredict', 'editentry');
						$logEntry->setPerformer($this->getUser());
						$logEntry->setTarget(Title::newFromText("Entry/$target", NS_SPECIAL));
						$logEntry->setParameters(array("6::tag" => $tag, "7::item" => $item->item_name, "8::mod" => $item->mod_name, "9::params" => $item->grid_params, "10::to_item" => $fItem, "11::to_mod" => $mod, "12::to_params" => $params, "13::id" => $item->entry_id, "4::diff" => $diffString, "5::diff_json" => json_encode($diff)));
						$logEntry->setComment("Updating entries using import tool.");
						$logId = $logEntry->insert();
						$logEntry->publish($logId);
						// End log

						$dbw->update('ext_oredict_items', array('grid_params' => $params), array('item_name' => $itemName, 'tag_name' => $tagName, 'mod_name' => $modName));
						$out->addHTML($this->returnMessage(true, wfMessage('oredict-import-success-update')->text()));
						continue;
					} else {
						// Otherwise display error
						$out->addHTML($this->returnMessage(false, wfMessage('oredict-import-fail-exists')->text()));
						continue;
					}
				}
				// Add entry
				$result = OreDict::addEntry($modName, $itemName, $tagName, $this->getUser(), $params);
				if ($result == false) {
					$out->addHTML($this->returnMessage(false, wfMessage('oredict-import-fail-insert')->text()));
					continue;
				}

				$out->addHTML($this->returnMessage(true, wfMessage('oredict-import-success-new', $result)->text()));
			}
			$out->addHtml('</tt>');
		} else {
			$out->addHtml($this->buildForm());
		}
	}

	/**
	 * Build the oredict creation form
	 *
	 * @return string
	 */

	private function buildForm() {
		global $wgArticlePath, $wgUser;
		$form = "<table style=\"width:100%;\">
					<tr>
						<td>".$this->msg('oredict-import-input')->text()."</td>
						<td></td>
					</tr>
					" . OreDictForm::createInputHint('import', 'input') . "
					<tr>
						<td colspan=\"2\">
							<textarea name=\"input\" style=\"width:100%; height: 600px;\"></textarea>
						</td>
					</tr>
					<tr>
						<td colspan=\"2\">
							<input type=\"submit\" value=\"".$this->msg("oredict-import-submit")->text()."\">
							<input type=\"checkbox\" value=\"1\" name=\"update_table\" id=\"update_table\">
							<label for=\"update_table\">".$this->msg("oredict-import-update")->text()."</label>
							<span style=\"font-size: x-small;padding: .2em .5em;color: #666;\">
								".$this->msg("oredict-import-update-hint")->parse()."
							</span>
						</td>
					</tr>
				</table>";

		$out = Xml::openElement('form', array('method' => 'post', 'action' => str_replace('$1', 'Special:ImportOreDict', $wgArticlePath), 'id' => 'ext-oredict-import-form'))
			 . Xml::fieldset($this->msg('oredict-import-legend')->text())
			 . Html::hidden('title', $this->getTitle()->getPrefixedText())
			 . Html::hidden('token', $wgUser->getEditToken())
			 . $form
			 . Xml::closeElement( 'fieldset' )
			 . Xml::closeElement( 'form' ) . "\n";

		return $out;
	}

	/**
	 * Helper function for displaying results
	 *
	 * @param bool $state Return value an action
	 * @param string $message Message to display
	 * @return string
	 */
	private function returnMessage($state, $message) {
		if ($state) {
			$bgColor = 'green';
			$resultMsg = wfMessage('oredict-import-success')->text();
		} else {
			$bgColor = 'red';
			$resultMsg = wfMessage('oredict-import-fail')->text();
		}

		return "<span style=\"background-color: $bgColor; font-weight: bold; color: white;\">$resultMsg</span> $message<br />";
	}
}
