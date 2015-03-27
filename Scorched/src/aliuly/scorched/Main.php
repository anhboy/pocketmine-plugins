<?php
namespace aliuly\scorched;

use pocketmine\plugin\PluginBase;
use pocketmine\command\CommandExecutor;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

use pocketmine\event\Listener;
use pocketmine\item\Item;
use pocketmine\entity\Entity;
use pocketmine\nbt\tag\Byte;
use pocketmine\nbt\tag\Compound;
use pocketmine\nbt\tag\Double;
use pocketmine\nbt\tag\Enum;
use pocketmine\nbt\tag\Float;

use pocketmine\event\player\PlayerItemHeldEvent;
use pocketmine\event\entity\EntityShootBowEvent;

class Main extends PluginBase implements CommandExecutor, Listener {
  protected $shooters;
  static $presets = [
		     "short" => [30, 0.5],
		     "long" => [80, 1.0],
		     "fast" => [20, 1.0],
		     ];

  // Access and other permission related checks
  private function access(CommandSender $sender, $permission) {
    if($sender->hasPermission($permission)) return true;
    $sender->sendMessage("You do not have permission to do that.");
    return false;
  }
  private function inGame(CommandSender $sender,$msg = true) {
    if ($sender instanceof Player) return true;
    if ($msg) $sender->sendMessage("You can only use this command in-game");
    return false;
  }

  // Event handlers
  public function onItemHeld(PlayerItemHeldEvent $e) {
    $p = $e->getPlayer();
    $n = $p->getName();
    if (!isset($this->shooters[$n])) return;
    if ($e->getItem()->getID() != Item::BOW) {
      unset($this->shooters[$n]);
      $p->sendMessage("Disarming RPG");
    }
  }
  public function onShoot(EntityShootBowEvent $e) {
    $p = $e->getEntity();
    if (!($p instanceof Player)) return;
    $n = $p->getName();
    if (!isset($this->shooters[$n])) return;
    if ($e->isCancelled()) return;
    if (!isset($this->shooters[$n])) return;
    $e->setCancelled(); // Disable it and replace it with our own

    if (!$this->checkAmmo($p,true)) {
      $p->sendMessage("You are out of grenades");
      $p->sendMessage("RPG disarmed");
      unset($this->shoters[$n]);
      return;
    }

    // $e->getBow(); // Check if damage and if below number can malfunction
    // $e->getForce(); // always returns the same value of 1.5!

    $this->fire($p,$this->shooters[$n][0],$this->shooters[$n][1]);
  }

  // Standard call-backs
  public function onDisable() {
    //$this->getLogger()->info("- Scorched Unloaded!");
  }
  public function onEnable(){
    //$this->getLogger()->info("* Scorched Enabled!");
    $this->shooters = [];
    $this->getServer()->getPluginManager()->registerEvents($this, $this);
  }
  public function onCommand(CommandSender $sender, Command $cmd, $label, array $args) {
    switch($cmd->getName()) {
    case "rpg":
      if (!$this->access($sender,"scorched.cmd.fire")) return true;
      return $this->cmdRpg($sender,$args);
    case "fire":
      if (!$this->access($sender,"scorched.cmd.fire")) return true;
      return $this->cmdFire($sender,$args);
    }
    return false;
  }
  private function checkAmmo(Player $p,$adj=false) {
    foreach ($p->getInventory()->getContents() as $slot=>$item) {
      if ($item->getID() != Item::TNT || $item->getCount() == 0) continue;

      if ($adj) {
	$count = $item->getCount();
	if ($count == 1) {
	  // The last one...
	  $c->getInventory()->clear($slot);
	} else {
	  $item->setCount($count-1);
	  $p->getInventory()->setItem($slot,$item);
	}
      }
      return true;
    }
    // Run out of grenades, disarm ...
    if (isset($this->shooters[$p->getName()])) {
      unset($this->shooters[$p->getName()]);
    }
    return false;
  }

  // Command implementations
  private function cmdFire(CommandSender $c,$args) {
    if (!$this->inGame($c)) return false;

    $fuse = 40;
    $speed = 0.75;

    if (count($args)) {
      if (isset(self::$presets[$args[0]])) $args = self::$presets[$args[0]];
      if (count($args) != 2) return false;
      $fuse = (int)$args[0];
      $speed = (float)$args[1];
      if ($speed > 4.0) $speed = 4.0;
      if ($speed < 0.5) $speed = 0.5;
      if ($fuse > 120) $fuse = 120;
      if ($fuse < 10) $fuse = 10;
    }

    if (!$c->isCreative()) {
      // Not in creative, we need to check inventories...
      if (!$this->checkAmmo($c,true)) {
	$c->sendMessage("Unable to fire RPG, you are out of grenades");
	return true;
      }
    }
    $c->sendMessage("Firing...");
    $this->fire($c,$fuse,$speed);
    return true;
  }
  private function cmdRpg(CommandSender $c,$args) {
    if (!$this->inGame($c)) return false;
    $n = $c->getName();
    if (count($args) == 0) {
      if (isset($this->shooters[$n])) {
	$c->sendMessage("RPG armed");
	$c->sendMessage("- Fuse:  ".$this->shooters[$n][0]);
	$c->sendMessage("- Speed: ".$this->shooters[$n][1]);
      } else {
	$c->sendMessage("RPG not armed");
      }
      return true;
    }
    if ($args[0] == "disarm" || $args[0] == "off") {
      if (isset($this->shooters[$n])) {
	unset($this->shooters[$n]);
	$c->sendMessage("Disarming RPG");
	return true;
      }
      $c->sendMessage("RPG disarmed");
      return true;
    }
    if (!$c->isCreative()) {
      // Not in creative, we need to check inventories...
      if (!$this->checkAmmo($c)) {
	$c->sendMessage("Unable to arm RPG, you are out of grenades");
	return true;
      }
    }
    $hand = $c->getInventory()->getItemInHand();
    if ($hand->getID() != Item::BOW) {
      $c->sendMessage("Unable to arm RPG, you must be holding a Bow");
      return true;
    }

    if (isset(self::$presets[$args[0]])) $args = self::$presets[$args[0]];
    if (count($args) != 2) return false;
    $fuse = (int)$args[0];
    $speed = (float)$args[1];
    if ($speed > 4.0) $speed = 4.0;
    if ($speed < 0.5) $speed = 0.5;
    if ($fuse > 120) $fuse = 120;
    if ($fuse < 10) $fuse = 10;

    $this->shooters[$n] = [(int)$fuse,(float)$speed];
    $c->sendMessage("RPG armed!");
    return true;
  }


  private function fire(Player $c,$fuse,$speed) {
    $pos = $c->getPosition();
    $pos->y += $c->getEyeHeight();
    $speed = 2.5;

    $dir = $c->getDirectionVector();
    $dir->x = $dir->x * $speed;
    $dir->y = $dir->y * $speed;
    $dir->z = $dir->z * $speed;

    $nbt =
      new Compound("", ["Pos" => new Enum("Pos", [new Double("", $pos->x),
						  new Double("", $pos->y),
						  new Double("", $pos->z)]),
			"Motion" => new Enum("Motion",[new Double("",$dir->x),
						       new Double("",$dir->y),
						       new Double("",$dir->z)]),
			"Rotation" => new Enum("Rotation", [new Float("", 0),
							    new Float("", 0)]),
			"Fuse" => new Byte("Fuse", $fuse)]);

    $entity = Entity::createEntity("PrimedTNT",
				   $pos->getLevel()->getChunk($pos->x >> 4, $pos->z >> 4),
				   $nbt);
				       $entity->namedtag->setName("EssNuke");
    $entity->spawnToAll();

    return true;
  }
}
