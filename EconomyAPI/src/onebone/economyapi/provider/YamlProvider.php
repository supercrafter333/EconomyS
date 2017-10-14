<?php

/*
 * EconomyS, the massive economy plugin with many features for PocketMine-MP
 * Copyright (C) 2013-2017  onebone <jyc00410@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace onebone\economyapi\provider;


use onebone\economyapi\EconomyAPI;
use pocketmine\Player;
use pocketmine\scheduler\PluginTask;
use pocketmine\utils\Config;

class YamlProvider implements Provider{
	/**
	 * @var Config
	 */
	private $config;
	private $file;

	/** @var EconomyAPI */
	private $plugin;

	private $money = [];

	public function __construct(EconomyAPI $plugin){
		$this->plugin = $plugin;
	}

	public function open(){
		$this->config = new Config($this->file = $this->plugin->getDataFolder() . "Money.yml", Config::YAML, ["version" => 2, "money" => []]);
		$this->money = $this->config->getAll();
	}

	public function accountExists($player){
		if($player instanceof Player){
			$player = $player->getName();
		}
		$player = strtolower($player);

		return isset($this->money["money"][$player]);
	}

	public function createAccount($player, $defaultMoney = 1000){
		if($player instanceof Player){
			$player = $player->getName();
		}
		$player = strtolower($player);

		if(!isset($this->money["money"][$player])){
			$this->money["money"][$player] = $defaultMoney;
			return true;
		}
		return false;
	}

	public function removeAccount($player){
		if($player instanceof Player){
			$player = $player->getName();
		}
		$player = strtolower($player);

		if(isset($this->money["money"][$player])){
			unset($this->money["money"][$player]);
			return true;
		}
		return false;
	}

	public function getMoney($player){
		if($player instanceof Player){
			$player = $player->getName();
		}
		$player = strtolower($player);

		if(isset($this->money["money"][$player])){
			return $this->money["money"][$player];
		}
		return false;
	}

	public function setMoney($player, $amount){
		if($player instanceof Player){
			$player = $player->getName();
		}
		$player = strtolower($player);

		if(isset($this->money["money"][$player])){
			$this->money["money"][$player] = $amount;
			$this->money["money"][$player] = round($this->money["money"][$player], 2);
			return true;
		}
		return false;
	}

	public function addMoney($player, $amount){
		if($player instanceof Player){
			$player = $player->getName();
		}
		$player = strtolower($player);

		if(isset($this->money["money"][$player])){
			$this->money["money"][$player] += $amount;
			$this->money["money"][$player] = round($this->money["money"][$player], 2);
			return true;
		}
		return false;
	}

	public function reduceMoney($player, $amount){
		if($player instanceof Player){
			$player = $player->getName();
		}
		$player = strtolower($player);

		if(isset($this->money["money"][$player])){
			$this->money["money"][$player] -= $amount;
			$this->money["money"][$player] = round($this->money["money"][$player], 2);
			return true;
		}
		return false;
	}

	public function getAll(){
		return isset($this->money["money"]) ? $this->money["money"] : [];
	}

	public function save(){
		$this->config->setAll($this->money);
		if(!$this->plugin->getServer()->isRunning()){
			$this->config->save(); // synchronous save on config
		}else{
			$task = new class($this->plugin) extends PluginTask{
				private $i = 0;
				public function onRun(int $t){
					$keys = array_slice($this->keys, $this->i, 100);
					$data = [];
					foreach($keys as $key) $data[$key] = $this->data[$key];
					// WARNING: Extremely hacky
					// NOTE: Always check against latest libyaml behaviour
					$yaml = yaml_emit(["x" => $data], YAML_UTF8_ENCODING, YAML_LN_BREAK);
					$pos1 = strpos($yaml, "\n", strpos($yaml, "\n") + 1); // eliminate the --- and x:
					$pos2 = strrpos($yaml, "\n", -2); // eliminate the ...
					$yaml = substr($yaml, $pos1, $pos2 - $pos1);
					fwrite($this->fh, $yaml);
					$this->i += 100;
					if($this->i > count($this->data)){ // end of save
						fclose($this->fh);
					}else{
						$this->sc->scheduleDelayedTask($this, 1);
					}
				}
			};
			$task->keys = array_keys($this->money["money"]);
			$task->data = $this->money["money"]; // me lazy
			$task->fh = fopen($this->file, "wt");
			fwrite($task->fh, "money:\n");
			$task->sc = $this->plugin->getServer()->getScheduler();
			$task->onRun(0);
		}
	}

	public function close(){
		$this->save();
	}

	public function getName(){
		return "Yaml";
	}
}
