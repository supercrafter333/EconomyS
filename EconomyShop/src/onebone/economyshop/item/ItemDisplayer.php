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

namespace onebone\economyshop\item;

use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\world\World as Level;
use pocketmine\world\Position;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\AddItemActorPacket as AddItemEntityPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket as RemoveEntityPacket;
use pocketmine\player\Player;
use pocketmine\Server;

class ItemDisplayer
{

    private $eid;

    public function __construct(private Position $pos, private Item $item, private Position $linked)
    {
        $this->eid = Entity::nextRuntimeId();
    }

    public function spawnTo(Player $player){
        $pk = new AddItemEntityPacket;
        $pk->entityRuntimeId = $this->eid;
        $pk->item = ItemStackWrapper::legacy(TypeConverter::getInstance()->coreItemStackToNet($this->item));
        $position = new Vector3($this->pos->x + 0.5, $this->pos->y, $this->pos->z + 0.5);
        $motion = new Vector3(0, 0, 0);
        $pk->position = $position;
        $pk->motion = $motion;
        $player->getNetworkSession()->sendDataPacket($pk);
    }

    public function spawnToAll(Level $level = null){
        foreach($level instanceof Level ? $level->getPlayers() : Server::getInstance()->getOnlinePlayers() as $player){
            $this->spawnTo($player);
        }
    }

    public function despawnFrom(Player $player){
        $pk = new RemoveEntityPacket;
        $pk->entityUniqueId = $this->eid;
        $player->getNetworkSession()->sendDataPacket($pk);
    }

    public function despawnFromAll(Level $level = null){
        foreach($level instanceof Level ? $level->getPlayers() : Server::getInstance()->getOnlinePlayers() as $player){
            $this->despawnFrom($player);
        }
    }

    /**
     * @return Position
     */
    public function getLinked(){
        return $this->linked;
    }
}
