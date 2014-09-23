<?php
require_once("Exception.php");
require_once("openmediavault/util.inc");
require_once("Dataset.php");
require_once("Vdev.php");
require_once("Zpool.php");

/**
 * Helper class for ZFS module
 */
class OMVModuleZFSUtil {

	/**
	 * Clears all ZFS labels on specified devices.
	 * Needed for blkid to display proper data.
	 *
	 */
	public static function clearZFSLabel($disks) {
		foreach ($disks as $disk) {
			$cmd = "zpool labelclear /dev/" . $disk . "1";
			OMVModuleZFSUtil::exec($cmd,$out,$res);
		}
	}

	/**
	 * Return all disks in /dev/sdXX used by the pool
	 *
	 * @return array An array with all the disks
	 */
	public static function getDevDisksByPool($name) {
		$pool = new OMVModuleZFSZpool($name);
		$disks = array();
		$vdevs = $pool->getVdevs();
		foreach ($vdevs as $vdev) {
			$vdisks = $vdev->getDisks();
			foreach ($vdisks as $vdisk) {
				if (preg_match('/^[a-z0-9]+$/', $vdisk)) {
					$disks[] = $vdisk;
					continue;
				}
				$cmd = "ls -la /dev/disk/by-path/" . $vdisk;
				unset($out);
				OMVModuleZFSUtil::exec($cmd,$out,$res);
				if (count($out) === 1) {
					if (preg_match('/^.*\/([a-z0-9]+)$/', $out[0], $match)) {
						$disks[] = $match[1];
					}
				}
			}
		}
		return($disks);
	}

	/**
	 * Deletes all shared folders pointing to the specifc path
	 *
	 */
	public static function deleteShares($name) {
		global $xmlConfig;
		$tmp = new OMVModuleZFSDataset($name);
		$reldirpath = OMVModuleZFSUtil::getReldirpath($tmp->getMountPoint());
		$poolname = OMVModuleZFSUtil::getPoolname($name);
		$pooluuid = OMVModuleZFSUtil::getUUIDbyName($poolname);
		$xpath = "//system/fstab/mntent[fsname='" . $pooluuid . "']";
		$mountpoint = $xmlConfig->get($xpath);
		$mntentuuid = $mountpoint['uuid'];
		$xpath = "//system/shares/sharedfolder[mntentref='" . $mntentuuid . "' and reldirpath='" . $reldirpath . "']";
		$object = $xmlConfig->get($xpath);
		$xmlConfig->delete($xpath);
		$dispatcher = &OMVNotifyDispatcher::getInstance();
		$dispatcher->notify(OMV_NOTIFY_DELETE,"org.openmediavault.system.shares.sharedfolder",$object);
	}

	/**
	 * Get the relative path by complete path
	 *
	 * @return string Relative path of the complet path
	 */
	public static function getReldirpath($path) {
 		$subdirs = preg_split('/\//',$path);
		$reldirpath = "";
		for ($i=2;$i<count($subdirs);$i++) {
			$reldirpath .= $subdirs[$i] . "/";
		}
		return(rtrim($reldirpath, "/"));

	}

	/**
	 * Get /dev/disk/by-path from /dev/sdX
	 *
	 * @return string Disk identifier
	 */
	public static function getDiskPath($disk) {
		preg_match("/^.*\/([A-Za-z0-9]+)$/", $disk, $identifier);
		$cmd = "ls -la /dev/disk/by-path | grep '$identifier[1]$'";
		OMVModuleZFSUtil::exec($cmd, $out, $res);
		if (is_array($out)) {
			$cols = preg_split('/[\s]+/', $out[0]);
			return($cols[count($cols)-3]);
		}
	}


	/**
	 * Get poolname from name of dataset/volume etc.
	 *
	 * @return string Name of the pool
	 */
	public static function getPoolname($name) {
		$tmp = preg_split('/[\/]+/', $name);
		return($tmp[0]);
	}

	/**
	 * Get UUID of ZFS pool by name
	 *
	 * @return string UUID of the pool
	 */
	public static function getUUIDbyName($poolname) {
		$cmd = "zpool get guid " . $poolname . " 2>&1";
		OMVModuleZFSUtil::exec($cmd, $out, $res);
		if (isset($out)) {
			$headers = preg_split('/[\s]+/', $out[0]);
			for ($i=0; $i<count($headers); $i++) {
				if (strcmp($headers[$i], "VALUE") === 0) {
					$valuecol=$i;
					break;
				}
			}
			$line = preg_split('/[\s]+/', $out[1]);
			return $line[$valuecol];
		}
		return null;
	}

	/**
	 * Add any missing ZFS pool to the OMV backend
	 *
	 */
	public static function addMissingOMVMntEnt() {
		global $xmlConfig;
		$cmd = "zpool list -H -o name";
		OMVModuleZFSUtil::exec($cmd, $out, $res);
		foreach($out as $name) {
			$pooluuid = OMVModuleZFSUtil::getUUIDbyName($name);
			$xpath = "//system/fstab/mntent[fsname='" . $pooluuid . "']";
			$mountpoint = $xmlConfig->get($xpath);
			if (is_null($mountpoint)) {
				$uuid = OMVUtil::uuid();
				$pool = new OMVModuleZFSZpool($name);
				$dir = $pool->getMountPoint();
				$object = array(
					"uuid" => $uuid,
					"fsname" => $pooluuid,
					"dir" => $dir,
					"type" => "zfs",
					"opts" => "rw,relatime,xattr,noacl",
					"freq" => "0",
					"passno" => "0"
				);
				$xmlConfig->set("//system/fstab",array("mntent" => $object));
				$dispatcher = &OMVNotifyDispatcher::getInstance();
				$dispatcher->notify(OMV_NOTIFY_CREATE,"org.openmediavault.system.fstab.mntent", $object);
			}
		}
		return null;
	}

	/**
	 * Get an array with all ZFS objects
	 *
	 * @return An array with all ZFS objects
	 */
	public static function getZFSFlatArray() {
		$prefix = "root/pool-";
		$objects = array();
		$cmd = "zfs list -H -t all -o name,type 2>&1";
		$expanded = true;
		OMVModuleZFSUtil::exec($cmd,$out,$res);
		foreach ($out as $line) {
			$parts = preg_split('/\t/',$line);
			$path = $parts[0];
			$type = $parts[1];
			$subdirs = preg_split('/\//',$path);
			$root = $subdirs[0];
			$tmp = array();

			switch ($type) {
			case "filesystem":
				if (strpos($path,'/') === false) {
					//This is a Pool
					$tmp = array('id'=>$prefix . $path,
						'parentid'=>'root',
						'name'=>$path,
						'type'=>'Pool',
						'icon'=>'images/raid.png',
						'expanded'=>$expanded,
						'path'=>$path);
					array_push($objects,$tmp);
				} else {
					//This is a Filesystem
					preg_match('/(.*)\/(.*)$/', $path, $result);
					$tmp = array('id'=>$prefix . $path,
						'parentid'=>$prefix . $result[1],
						'name'=>$result[2],
						'icon'=>"images/filesystem.png",
						'path'=>$path,
						'expanded'=>$expanded);
					$ds =  new OMVModuleZFSDataset($path);
					if ($ds->isClone()) {
						//This is a cloned Filesystem
						$tmp['type'] = "Clone";
						$tmp['origin'] = $ds->getOrigin();
					} else {
						//This is a standard Filesystem.
						$tmp['type']= ucfirst($type);
					}
					array_push($objects,$tmp);
				}
				break;

			case "volume":
				preg_match('/(.*)\/(.*)$/', $path, $result);
				$tmp = array('id'=>$prefix . $path,
					'parentid'=>$prefix . $result[1],
					'name'=>$result[2],
					'type'=>ucfirst($type),
					'icon'=>"images/save.png",
					'path'=>$path,
					'expanded'=>$expanded);
				array_push($objects,$tmp);
				break;

			case "snapshot":
				preg_match('/(.*)\@(.*)$/', $path, $result);
				$subdirs = preg_split('/\//',$result[1]);
				$root = $subdirs[0];
				$tmp = array('id'=>$prefix . $path,
					'parentid'=>$prefix . $result[1],
					'name'=>$result[2],
					'type'=>ucfirst($type),
					'icon'=>'images/zfs_snap.png',
					'path'=>$path,
					'expanded'=>$expanded);
				array_push($objects,$tmp);
				break;

			default:
				break;
			}
		}
		return $objects;
	}

	/**
	 * Create a tree structured array
	 *
	 * @param &$list The flat array to convert to a tree structure
	 * @param $parent Root node of the tree to create
	 * @return Tree structured array
	 *
	 */
	public static function createTree(&$list, $parent){
		$tree = array();
		foreach ($parent as $k=>$l){
			if(isset($list[$l['id']])){
				$l['leaf'] = false;
				$l['children'] = OMVModuleZFSUtil::createTree($list, $list[$l['id']]);
			} else {
				$l['leaf'] = true;
			}
			$tree[] = $l;
		}
		return $tree;
	}

	/**
	 * Get all Datasets as objects
	 * 
	 * @return An array with all the Datasets
	 */
	public static function getAllDatasets() {
		$datasets = array();
		$cmd = "zfs list -H -t filesystem -o name 2>&1";
		OMVModuleZFSUtil::exec($cmd, $out, $res);
		foreach ($out as $name) {
			$ds = new OMVModuleZFSDataset($name);
			array_push($datasets, $ds);
		}
		return $datasets;
	}

	/**
	 * Helper function to execute a command and throw an exception on error
	 * (requires stderr redirected to stdout for proper exception message).
	 * 
	 * @param string $cmd Command to execute
	 * @param array &$out If provided will contain output in an array
	 * @param int &$res If provided will contain Exit status of the command
	 * @return string Last line of output when executing the command
	 * @throws OMVModuleZFSException
	 * @access public
	 */
	public static function exec($cmd, &$out = null, &$res = null) {
		$tmp = OMVUtil::exec($cmd, $out, $res);
		if ($res) {
			throw new OMVModuleZFSException(implode("\n", $out));
		}
		return $tmp;
	}

}


?>
