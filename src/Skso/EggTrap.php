<?php

namespace Skso;

use pocketmine\block\VanillaBlocks;
use pocketmine\block\Cobweb;
use pocketmine\event\entity\ProjectileHitEntityEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\entity\projectile\Egg;
use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\scheduler\Task;
use pocketmine\utils\Config;
use pocketmine\world\Position;
use pocketmine\player\Player;
use pocketmine\Server;

class EggTrap extends PluginBase implements Listener
{
    public array $trapData = [];
    private array $cooldownTimes = [];

    public function onEnable(): void
    {
        $this->registerEvents();
        $this->initializeConfig();
    }

    private function registerEvents(): void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    private function initializeConfig(): void
    {
        $configPath = $this->getDataFolder() . "settings.yml";
        if (!file_exists($configPath)) {
            new Config($configPath, Config::YAML, [
                "duration" => 10,
                "cooldown" => 15,
                "popup" => "Vous devez attendre encore {time} !"
            ]);
        }
    }

    public function handleProjectileLaunch(ProjectileLaunchEvent $event): void
    {
        $entity = $event->getEntity();
        if ($entity instanceof Egg) {
            $player = $entity->getOwningEntity();
            if ($player instanceof Player) {
                $this->manageLaunchCooldown($player, $event);
            }
        }
    }

    private function manageLaunchCooldown(Player $player, ProjectileLaunchEvent $event): void
    {
        $playerName = $player->getName();
        $currentTime = time();

        if (empty($this->cooldownTimes[$playerName]) || $this->cooldownTimes[$playerName] < $currentTime) {
            $this->cooldownTimes[$playerName] = $currentTime + $this->getConfig()->get("cooldown");
        } else {
            $remainingTime = $this->cooldownTimes[$playerName] - $currentTime;
            $player->sendPopup(str_replace("{time}", $remainingTime, $this->getConfig()->get("popup")));
            $event->cancel();
        }
    }

    public function handleProjectileHit(ProjectileHitEntityEvent $event): void
    {
        $hitEntity = $event->getEntityHit();
        if ($hitEntity instanceof Player) {
            $projectile = $event->getEntity();
            if ($projectile instanceof Egg) {
                $this->applyCobwebs($hitEntity);
                $this->scheduleCobwebRemoval($hitEntity);
            }
        }
    }

    private function applyCobwebs(Player $player): void
    {
        $position = $player->getPosition();
        $blocksToPlace = $this->getSurroundingBlocks($position);
        $this->trapData[$player->getName()] = $blocksToPlace;

        foreach ($blocksToPlace as $block) {
            $pos = new Position($block[0], $block[1], $block[2], $position->getWorld());
            if ($position->getWorld()->getBlock($pos)->getTypeId() === VanillaBlocks::AIR()->getTypeId()) {
                $position->getWorld()->setBlock($pos, VanillaBlocks::COBWEB(), true);
            }
        }
    }

    private function getSurroundingBlocks(Position $position): array
    {
        $x = $position->getX();
        $y = $position->getY();
        $z = $position->getZ();
        $worldId = $position->getWorld()->getId();

        return [
            [$x, $y, $z + 1, $worldId],
            [$x + 1, $y, $z, $worldId],
            [$x - 1, $y, $z, $worldId],
            [$x, $y + 2, $z, $worldId],
            [$x, $y, $z - 1, $worldId],
            [$x, $y + 1, $z - 1, $worldId],
            [$x - 1, $y + 1, $z, $worldId],
            [$x + 1, $y + 1, $z, $worldId],
            [$x, $y + 1, $z + 1, $worldId]
        ];
    }

    private function scheduleCobwebRemoval(Player $player): void
    {
        $duration = $this->getConfig()->get("duration");
        $playerName = $player->getName();

        $this->getScheduler()->scheduleDelayedTask(new class($playerName, $duration, $this) extends Task {
            private string $playerName;
            private int $remainingTime;
            private TrapEggPlugin $plugin;

            public function __construct(string $playerName, int $duration, TrapEggPlugin $plugin)
            {
                $this->playerName = $playerName;
                $this->remainingTime = $duration;
                $this->plugin = $plugin;
            }

            public function onRun(): void
            {
                if ($this->remainingTime <= 0) {
                    $this->removeCobwebs();
                    $this->getHandler()->cancel();
                } else {
                    $this->remainingTime--;
                }
            }

            private function removeCobwebs(): void
            {
                $blocks = $this->plugin->trapData[$this->playerName] ?? [];
                $worldManager = Server::getInstance()->getWorldManager();

                foreach ($blocks as $block) {
                    if (isset($block[0], $block[1], $block[2], $block[3])) {
                        $world = $worldManager->getWorld($block[3]);
                        if ($world !== null) {
                            $pos = new Position($block[0], $block[1], $block[2], $world);
                            if ($pos->getWorld()->getBlock($pos) instanceof Cobweb) {
                                $pos->getWorld()->setBlock($pos, VanillaBlocks::AIR(), true);
                            }
                        }
                    }
                }
            }
        }, 20 * $duration);
    }
}
