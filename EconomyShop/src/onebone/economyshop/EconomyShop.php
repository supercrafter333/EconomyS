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

namespace onebone\economyshop;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIds;
use pocketmine\item\StringToItemParser;
use pocketmine\math\Facing;
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\PermissionManager;
use pocketmine\world\Position;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

use onebone\economyapi\EconomyAPI;

use onebone\economyshop\provider\DataProvider;
use onebone\economyshop\provider\YamlDataProvider;
use onebone\economyshop\item\ItemDisplayer;
use onebone\economyshop\event\ShopCreationEvent;
use onebone\economyshop\event\ShopTransactionEvent;

class EconomyShop extends PluginBase implements Listener{
	/**
	 * @var DataProvider
	 */
	private $provider;

	private $lang;

	private $queue = [], $tap = [], $removeQueue = [], $placeQueue = [], $canBuy = [];

	/** @var ItemDisplayer[][] */
	private $items = [];

	public function onEnable(): void{
		$this->saveDefaultConfig();

		if(!$this->selectLang()){
			$this->getLogger()->warning("Invalid language option was given.");
		}

		$provider = $this->getConfig()->get("data-provider");
		switch(strtolower($provider)){
			case "yaml":
				$this->provider = new YamlDataProvider($this->getDataFolder()."Shops.yml", $this->getConfig()->get("auto-save"));
				break;
			default:
				$this->getLogger()->critical("Invalid data provider was given. EconomyShop will be terminated.");
				return;
		}
		$this->getLogger()->notice("Data provider was set to: ".$this->provider->getProviderName());

		$levels = [];
		foreach($this->provider->getAll() as $shop){
			if(!isset($shop[9]) or $shop[9] !== -2){
				$level = $shop["level"] ?? $shop[3];
				if(!isset($levels[$level])){
					$levels[$level] = $this->getServer()->getWorldManager()->getWorldByName($level);
				}
				$pos = new Position($shop["x"] ?? $shop[0], $shop["y"] ?? $shop[1], $shop["z"] ?? $shop[2], $levels[$level]);
				$display = $pos;
				if(isset($shop[9]) && $shop[9] !== -1){
					$display = $pos->getSide($shop[9]);
				}
				$this->items[$level][] = new ItemDisplayer($display, Itemfactory::getInstance()->get((int) ($shop["item"] ?? $shop[4]), (int) ($shop["meta"] ?? $shop[5]), (int) ($shop["amount"] ?? $shop[7])), $pos);
			}
		}

		$this->getServer()->getPluginManager()->registerEvents($this, $this);

        $economyShop = PermissionManager::getInstance()->getPermission("economyshop.*");
        $economyCmdShop = PermissionManager::getInstance()->getPermission("economyshop.command.shop");
        $economyShopShop = PermissionManager::getInstance()->getPermission("economyshop.shop.*");

        $economyShopShop->addChild("economyshop.shop.buy", true);
        $economyShopShop->recalculatePermissibles();

        $economyCmdShop->addChild("economyshop.command.shop.create", true);
        $economyCmdShop->recalculatePermissibles();
        $economyCmdShop->addChild("economyshop.command.shop.remove", true);
        $economyCmdShop->recalculatePermissibles();
        $economyCmdShop->addChild("economyshop.command.shop.list", true);
        $economyCmdShop->recalculatePermissibles();

        $economyShop->addChild($economyCmdShop->getName(), true);
        $economyCmdShop->recalculatePermissibles();
        $economyShop->addChild($economyShopShop->getName(), true);
        $economyShop->recalculatePermissibles();
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $params): bool{
		switch($command->getName()){
			case "shop":
				switch(strtolower(array_shift($params))){
					case "create":
					case "cr":
					case "c":
						if(!$sender instanceof Player){
							$sender->sendMessage(TextFormat::RED."Please run this command in-game.");
							return true;
						}
						if(!$sender->hasPermission("economyshop.command.shop.create")){
							$sender->sendMessage(TextFormat::RED."You don't have permission to run this command.");
							return true;
						}
						if(isset($this->queue[strtolower($sender->getName())])){
							unset($this->queue[strtolower($sender->getName())]);
							$sender->sendMessage($this->getMessage("removed-queue"));
							return true;
						}
						$item = array_shift($params);
						$amount = array_shift($params);
						$price = array_shift($params);
						$side = array_shift($params);

						if(trim($item) === "" or trim($amount) === "" or trim($price) === "" or !is_numeric($amount) or !is_numeric($price)){
							$sender->sendMessage("Usage: /shop create <item[:damage]> <amount> <price> [side]");
							return true;
						}

						if(trim($side) === ""){
							$side = Facing::UP;
						}else{
							switch(strtolower($side)){
								case "up": case Facing::UP: $side = Facing::UP;break;
								case "down": case Facing::DOWN: $side = Facing::DOWN;break;
								case "west": case Facing::WEST: $side = Facing::WEST;break;
								case "east": case Facing::EAST: $side = Facing::EAST;break;
								case "north": case Facing::NORTH: $side = Facing::NORTH;break;
								case "south": case Facing::SOUTH: $side = Facing::SOUTH;break;
								case "shop": case -1: $side = -1;break;
								case "none": case -2: $side = -2;break;
								default:
									$sender->sendMessage($this->getMessage("invalid-side"));
									return true;
							}
						}
						$this->queue[strtolower($sender->getName())] = [
							$item, (int)$amount, $price, (int)$side
						];
						$sender->sendMessage($this->getMessage("added-queue"));
						return true;
					case "remove":
					case "rm":
					case "r":
					case "delete":
					case "del":
					case "d":
						if(!$sender instanceof Player){
							$sender->sendMessage(TextFormat::RED."Please run this command in-game.");
							return true;
						}
						if(!$sender->hasPermission("economyshop.command.shop.remove")){
							$sender->sendMessage(TextFormat::RED."You don't have permission to run this command.");
							return true;
						}
						if(isset($this->removeQueue[strtolower($sender->getName())])){
							unset($this->removeQueue[strtolower($sender->getName())]);
							$sender->sendMessage($this->getMessage("removed-rm-queue"));
							return true;
						}
						$this->removeQueue[strtolower($sender->getName())] = true;
						$sender->sendMessage($this->getMessage("added-rm-queue"));
						return true;
					case "list":

						return true;
				}
		}
		return false;
	}

	public function onPlayerJoin(PlayerJoinEvent $event){
		$player = $event->getPlayer();
		$level = $player->getWorld()->getFolderName();
		$this->canBuy[strtolower($player->getName())] = true;
		if (isset($this->items[$level])) {
			foreach ($this->items[$level] as $displayer) {
				$displayer->spawnTo($player);
			}
		}
	}

	public function onPlayerTeleport(EntityTeleportEvent $event){
		$player = $event->getEntity();
		if($player instanceof Player){
			if(($from = $event->getFrom()->getWorld()) !== ($to = $event->getTo()->getWorld())){
				if($from !== null and isset($this->items[$from->getFolderName()])){
					foreach($this->items[$from->getFolderName()] as $displayer){
						$displayer->despawnFrom($player);
					}
				}
				if($to !== null and isset($this->items[$to->getFolderName()])){
					foreach($this->items[$to->getFolderName()] as $displayer){
						$displayer->spawnTo($player);
					}
				}
			}
		}
	}

	public function onBlockTouch(PlayerInteractEvent $event){
		if($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK){
			return;
		}

		$player = $event->getPlayer();
		$block = $event->getBlock();

		$iusername = strtolower($player->getName());

		if(isset($this->queue[$iusername])){
			$signIds = [ItemIds::SIGN, ItemIds::SIGN_POST, ItemIds::WALL_SIGN];
			if(!$this->getConfig()->get("allow-any-block", true) && !in_array($block->getIdInfo()->getItemId(), $signIds)) {
				$player->sendMessage($this->getMessage("shop-create-allow-any-block"));
				return;
			}
			$queue = $this->queue[$iusername];

			$item = StringToItemParser::getInstance()->parse($queue[0]);
			$item->setCount($queue[1]);

			$ev = new ShopCreationEvent($block->getPosition(), $item, $queue[2], $queue[3]);
			$ev->call();

			if($ev->isCancelled()){
				$player->sendMessage($this->getMessage("shop-create-failed"));
				unset($this->queue[$iusername]);
				return;
			}
			$result = $this->provider->addShop($block->getPosition(), [
				$block->getPosition()->getX(), $block->getPosition()->getY(), $block->getPosition()->getZ(), $block->getPosition()->getWorld()->getFolderName(),
				$item->getID(), $item->getDamage(), $item->getName(), $queue[1], $queue[2], $queue[3]
			]);

			if($result){
				if($queue[3] !== -2){
					$pos = $block;
					if($queue[3] !== -1){
						$pos = $block->getSide($queue[3]);
					}

					$this->items[$pos->getPosition()->getWorld()->getFolderName()][] = ($dis = new ItemDisplayer($pos->getPosition(), $item, $block->getPosition()));
					$dis->spawnToAll($pos->getPosition()->getWorld());
				}

				$player->sendMessage($this->getMessage("shop-created"));
			}else{
				$player->sendMessage($this->getMessage("shop-already-exist"));
			}

			if($event->getItem()->canBePlaced()){
				$this->placeQueue[$iusername] = true;
			}

			unset($this->queue[$iusername]);
			return;
		}elseif(isset($this->removeQueue[$iusername])){
			$shop = $this->provider->getShop($block->getPosition());
			foreach($this->items as $level => $arr){
				foreach($arr as $key => $displayer){
					$link = $displayer->getLinked();
					if($link->getWorld() !== null && ($link->getX() === ($shop["x"] ?? $shop[0])) && ($link->getY() === ($shop["y"] ?? $shop[1])) && ($link->getZ() === ($shop["z"] ?? $shop[2])) && $link->getWorld()->getFolderName() === ($shop["level"] ?? $shop[3])){
						$displayer->despawnFromAll();
						unset($this->items[$key]);
						break 2;
					}
				}
			}

			$this->provider->removeShop($block->getPosition());

			unset($this->removeQueue[$iusername]);
			$player->sendMessage($this->getMessage("shop-removed"));

			if($event->getItem()->canBePlaced()){
				$this->placeQueue[$iusername] = true;
			}
			return;
		}

		if (($shop = $this->provider->getShop($block->getPosition())) !== false && $this->canBuy[$iusername] == true) {
			if ($this->getConfig()->get("enable-double-tap")) {
				$now = time();
				if(isset($this->tap[$iusername]) and $now - $this->tap[$iusername] < 1){
					$this->buyItem($player, $shop);
					unset($this->tap[$iusername]);
				}else{
					$this->tap[$iusername] = $now;
					$player->sendMessage($this->getMessage("tap-again", [$shop["itemName"] ?? $shop[6], $shop["amount"] ?? $shop[7], $shop["price"] ?? $shop[8]]));
					return;
				}
			} else {
				$this->buyItem($player, $shop);
			}
			if ($event->getItem()->canBePlaced()) {
				$this->placeQueue[$iusername] = true;
			}
			$this->canBuy[$iusername] = false;
		}
	}

	public function onPlayerMove(PlayerMoveEvent $event) {
		$iusername = strtolower($event->getPlayer()->getName());
		$this->canBuy[$iusername] = true;
	}

	public function onBlockPlace(BlockPlaceEvent $event){
		$iusername = strtolower($event->getPlayer()->getName());
		if(isset($this->placeQueue[$iusername])){
			$event->cancel();
			unset($this->placeQueue[$iusername]);
		}
	}

	public function onBlockBreak(BlockBreakEvent $event){
		$block = $event->getBlock();
		if($this->provider->getShop($block->getPosition()) !== false){
			$player = $event->getPlayer();

			$event->cancel();
			$player->sendMessage($this->getMessage("shop-breaking-forbidden"));
		}
	}

	private function buyItem(Player $player, $shop){
		if(!$player instanceof Player){
			return false;
		}
		if(!$player->hasPermission("economyshop.shop.buy")){
			$player->sendMessage($this->getMessage("no-permission-buy"));
			return false;
		}

		$money = EconomyAPI::getInstance()->myMoney($player);
		if($money < ($shop["price"] ?? $shop[8])){
			$player->sendMessage($this->getMessage("no-money", [$shop["price"] ?? $shop[8], $shop["itemName"] ?? $shop[6]]));
		}else{
			if (is_string($shop["item"] ?? $shop[4])){
				$itemId = StringToItemParser::getInstance()->parse((string) ($shop["item"] ?? $shop[4]), false)->getId();
			}else{
				$itemId = ItemFactory::getInstance()->get((int) ($shop["item"] ?? $shop[4]), false)->getId();
			}
			$item = ItemFactory::getInstance()->get($itemId, (int) ($shop["meta"] ?? $shop[5]), (int) ($shop["amount"] ?? $shop[7]));
			if($player->getInventory()->canAddItem($item)){
				$ev = new ShopTransactionEvent($player, new Position($shop["x"] ?? $shop[0], $shop["y"] ?? $shop[1], $shop["z"] ?? $shop[2], $this->getServer()->getWorldManager()->getWorldByName($shop["level"] ?? $shop[3])), $item, ($shop["price"] ?? $shop[8]));
				$ev->call();
				if($ev->isCancelled()){
					$player->sendMessage($this->getMessage("failed-buy"));
					return true;
				}
				$player->getInventory()->addItem($item);
				$player->sendMessage($this->getMessage("bought-item", [$shop["itemName"] ?? $shop[6], $shop["amount"] ?? $shop[7], $shop["price"] ?? $shop[8]]));
				EconomyAPI::getInstance()->reduceMoney($player, $shop["price"] ?? $shop[8]);
			}else{
				$player->sendMessage($this->getMessage("full-inventory"));
			}
		}
		return true;
	}

	public function getMessage($key, $replacement = []){
		$key = strtolower($key);
		if(isset($this->lang[$key])){
			$search = [];
			$replace = [];
			$this->replaceColors($search, $replace);

			$search[] = "%MONETARY_UNIT%";
			$replace[] = EconomyAPI::getInstance()->getMonetaryUnit();
			$replacecount = count($replacement);
			for($i = 1; $i <= $replacecount; $i++){
				$search[] = "%".$i;
				$replace[] = $replacement[$i - 1];
			}
			return str_replace($search, $replace, $this->lang[$key]);
		}
		return "Could not find \"$key\".";
	}

	private function selectLang(){
		foreach(preg_grep("/.*lang_.{2}\\.json$/", $this->getResources()) as $resource){
			$lang = substr($resource, -7, -5);
			if($this->getConfig()->get("lang", "en") === $lang){
				$this->lang = json_decode((stream_get_contents($rsc = $this->getResource("lang_".$lang.".json"))), true);
				@fclose($rsc);
				return true;
			}
		}
		$this->lang = json_decode((stream_get_contents($rsc = $this->getResource("lang_en.json"))), true);
		@fclose($rsc);
		return false;
	}

	private function replaceColors(&$search = [], &$replace = []){
		$colors = [
			"BLACK" => "0",
			"DARK_BLUE" => "1",
			"DARK_GREEN" => "2",
			"DARK_AQUA" => "3",
			"DARK_RED" => "4",
			"DARK_PURPLE" => "5",
			"GOLD" => "6",
			"GRAY" => "7",
			"DARK_GRAY" => "8",
			"BLUE" => "9",
			"GREEN" => "a",
			"AQUA" => "b",
			"RED" => "c",
			"LIGHT_PURPLE" => "d",
			"YELLOW" => "e",
			"WHITE" => "f",
			"OBFUSCATED" => "k",
			"BOLD" => "l",
			"STRIKETHROUGH" => "m",
			"UNDERLINE" => "n",
			"ITALIC" => "o",
			"RESET" => "r"
		];
		foreach($colors as $color => $code){
			$search[] = "%%".$color."%%";
			$search[] = "&".$code;

			$replace[] = TextFormat::ESCAPE.$code;
			$replace[] = TextFormat::ESCAPE.$code;
		}
	}

	public function onDisable(): void
    {
		if($this->provider instanceof DataProvider){
			$this->provider->close();
		}
	}
}