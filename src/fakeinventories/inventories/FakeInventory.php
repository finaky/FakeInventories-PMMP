<?php

declare(strict_types=1);

namespace fakeinventories\inventories;

use fakeinventories\Main;
use fakeinventories\utils\TaskUtil;
use pocketmine\block\Block;
use pocketmine\block\inventory\BlockInventory;
use pocketmine\block\tile\Chest;
use pocketmine\block\tile\Nameable;
use pocketmine\block\tile\Tile;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\VanillaBlocks;
use pocketmine\inventory\SimpleInventory;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\InventorySlotPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\Position;

abstract class FakeInventory extends SimpleInventory implements BlockInventory {

    protected Position $holder;

    public ?self $nextInventory = null;

    protected bool $isClosed = false;
    protected bool $hasChanged = false;

    /** @var Vector3[] */
    protected array $chests = [];

    public function __construct(protected string $title = "Fake Inventory", protected int $size = FakeInventorySize::SMALL_CHEST, protected bool $inventoryBehindPlayer = true) {
        parent::__construct($size);

        $this->nextInventory = $this;
        $this->holder = new Position(0, 0, 0, null);
        $this->clearAll();
        $this->setItems();
    }

    public static function getBlockBehindPlayer(Player $player) : Vector3 {
        $yaw = $player->getLocation()->getYaw();

        $rotation = fmod($yaw - 90, 360);
        if($rotation < 0){
            $rotation += 360.0;
        }

        if((0 <= $rotation && $rotation < 45) || (315 <= $rotation && $rotation < 360)){
            return $player->getPosition()->floor()->add(2, 0, 0); //North
        }elseif(45 <= $rotation && $rotation < 135){
            return $player->getPosition()->floor()->add(0, 0, 2); //East
        }elseif(135 <= $rotation && $rotation < 225){
            return $player->getPosition()->floor()->subtract(2, 0, 0); //South
        }elseif(225 <= $rotation && $rotation < 315){
            return $player->getPosition()->floor()->subtract(0, 0, 2); //West
        }else{
            return $player->getPosition()->floor();
        }
    }

    abstract public function setItems() : void;

    abstract public function onTransaction(Player $player, Item $sourceItem, Item $targetItem, int $slot) : bool;

    private function blockPacket(Vector3 $pos, Block $block) : UpdateBlockPacket {
        return UpdateBlockPacket::create(
            new BlockPosition($pos->x, $pos->y, $pos->z),
            TypeConverter::getInstance()->getBlockTranslator()->internalIdToNetworkId($block->getStateId()),
            0,
            0
        );
    }

    private function blockActorDataPacket(Vector3 $pos, ?Vector3 $pair) : BlockActorDataPacket {
        $blockActorDataTag = CompoundTag::create()
            ->setString(Tile::TAG_ID, TileFactory::getInstance()->getSaveId(Chest::class))
            ->setInt(Tile::TAG_X, $pos->x)
            ->setInt(Tile::TAG_Y, $pos->y)
            ->setInt(Tile::TAG_Z, $pos->z)
            ->setString(Nameable::TAG_CUSTOM_NAME, $this->title);

        if($pair !== null) {
            $blockActorDataTag
                ->setInt(Chest::TAG_PAIRX, $pair->x)
                ->setInt(Chest::TAG_PAIRZ, $pair->z);
        }

        return BlockActorDataPacket::create(
            new BlockPosition($pos->x, $pos->y, $pos->z),
            new CacheableNbt($blockActorDataTag)
        );
    }

    public function onClose(Player $who) : void {
        $this->isClosed = true;

        parent::onClose($who);

        if(!$this->hasChanged)
            $this->closeFor($who);

        Main::getInstance()->getFakeInventoryManager()->unsetInventory($who->getName());

        if(Server::getInstance()->isRunning()) {
            TaskUtil::sendTask(function() : void {
                $this->hasChanged = false;
            });
        }
    }

    public function closeFor(Player $player) : void {
        foreach($this->chests as $key => $position) {
            $block = $player->getWorld()->getBlock($position);
            $player->getNetworkSession()->sendDataPacket($this->blockPacket($block->getPosition(), $block));
        }

        parent::onClose($player);
    }

    /**
     * @param Player[] $players
     */
    public function openFor(array $players) : void {
        if($this->size === FakeInventorySize::LARGE_CHEST) {
            $this->hasChanged = true;
        }

        foreach($players as $player) {
            if($player->getCurrentWindow() instanceof FakeInventory) {
                $player->removeCurrentWindow();
            }
        }

        $chestPosition = $this->getChestPosition($players);

        $pos = $chestPosition->add(0, 2, 0);
        $this->holder = new Position($pos->x, $pos->y, $pos->z, Server::getInstance()->getWorldManager()->getDefaultWorld());

        $this->sendAll($players, $pos);

        foreach($players as $player) {
            Main::getInstance()->getFakeInventoryManager()->setInventory($player->getName(), $this);
            $player->setCurrentWindow($this);
        }
    }

    public function changeInventory(Player $player, FakeInventory $inventory) : void {
        $this->isClosed = true;

        $this->nextInventory = clone $inventory;

        if($this->size !== $inventory->getSize()) {
            $inventory->hasChanged = true;
            $this->hasChanged = true;

            TaskUtil::sendTask(function() use ($player) : void {
                $this->closeFor($player);
            });
        } else {
            if((!$this->holder->equals($this->getChestPosition([$player])))) {
                TaskUtil::sendTask(function() use ($player) : void {
                    $this->closeFor($player);
                });
            }
        }

        TaskUtil::sendTask(function() use ($inventory, $player) : void {
            $inventory->openFor([$player]);
        });
    }

    /**
     * @param Player[] $players
     * @param Vector3 $vector3
     */
    public function sendAll(array $players, Vector3 $vector3) : void {
        $position = clone $vector3;
        $packets = [];

        $packets[] = $this->blockPacket($position, VanillaBlocks::CHEST());
        $this->chests[] = $position;

        if($this->size === FakeInventorySize::LARGE_CHEST) {
            $this->chests[] = $position->add(1, 0, 0);
            $packets[] = $this->blockPacket($position->add(1, 0, 0), VanillaBlocks::CHEST());
        }

        $packets[] = $this->blockActorDataPacket($vector3, $position->add(1, 0, 0));

        foreach($players as $player) {
            $player->getNetworkSession()->getBroadcaster()->broadcastPackets([$player->getNetworkSession()], $packets);
        }
    }

    /**
     * @param Player[] $players
     * @return Vector3
     */
    public function getChestPosition(array $players) : Vector3 {
        $chestPosition = new Vector3(0, 0, 0);

        if(count($players) <= 1) {
            foreach($players as $player) {
                $chestPosition = self::getBlockBehindPlayer($player);

                if(!$this->inventoryBehindPlayer) {
                    $chestPosition = $player->getPosition()->floor();
                }
            }
        } else {
            $x = round(($players[0]->getPosition()->x + $players[1]->getPosition()->x) / 2);
            $y = round(($players[0]->getPosition()->y + $players[1]->getPosition()->y) / 2);
            $z = round(($players[0]->getPosition()->z + $players[1]->getPosition()->z) / 2);

            $chestPosition = (new Vector3($x, $y, $z))->floor();
        }

        return $chestPosition;
    }

    public function unClickItem(Player $player) : void {
        $packet = new InventorySlotPacket();
        $packet->windowId = ContainerIds::UI;
        $packet->inventorySlot = 0;
        $packet->item = ItemStackWrapper::legacy(ItemStack::null());
        $player->getNetworkSession()->sendDataPacket($packet);
    }

    public function fill(Item $item) : void {
        for($i = 0; $i < $this->getSize(); $i++)
            if($this->isSlotEmpty($i)) {
                $this->setItem($i, $item->setCustomName(" "));
            }
    }

    public function fillWithPattern(array $pattern, Item $item) : void {
        foreach($pattern as $slot)
            $this->setItem($slot,  $item->setCustomName(" "));
    }

    public function setItem(int $index, Item $item, bool $send = true, bool $reset = false) : void {
        if($reset && $item->getTypeId() !== VanillaBlocks::AIR()->asItem()->getTypeId() && $item->getCustomName() !== "") {
            $item->setCustomName("§r" . $item->getCustomName());
        }

        parent::setItem($index, $item);
    }

    public function setItemAt(int $x, int $y, Item $item, bool $send = true, bool $reset = true) : void {
        $this->setItem((9 * $y - (9 - $x)) - 1, $item, $send, $reset);
    }

    public function getItemAt(int $x, int $y) : Item {
        return $this->getItem((9 * $y - (9 - $x)) - 1);
    }

    public function getSlotAt(int $x, int $y) : int {
        return (9 * $y - (9 - $x)) - 1;
    }

    public function getNetworkType() : int {
        return WindowTypes::CONTAINER;
    }

    public function getName() : string {
        return "Fake Inventory";
    }

    public function getTitle() : string {
        return $this->title;
    }

    public function setTitle(string $title) : void {
        $this->title = $title;
    }

    public function getDefaultSize() : int {
        return $this->size;
    }

    public function getHolder() : Position {
        return $this->holder;
    }

    public function isClosed() : bool {
        return $this->isClosed;
    }

    public function isChanging() : bool {
        return $this->hasChanged;
    }

    public function hasChanged() : bool {
        return $this->hasChanged;
    }
}
