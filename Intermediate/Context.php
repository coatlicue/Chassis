<?php
namespace Chassis\Intermediate;

const VAR_CHANNEL_NORMAL = 0; //เก็บตัวแปรปกติ
const VAR_CHANNEL_RESERVED = 1; //เก็บตัวแปรที่สงวนไว้ให้ใช้ในระบบ
/**
 * คลาสนี้เอาไว้เก็บตัวแปร ระหว่างการรันเทมเพลต
 */
class Context
{
	/**
	 * เก็บรายการตัวแปร แบ่งตามช่องตัวแปร
	 * มีโครงสร้างดังนี้ : $var_list[ช่องตัวแปร][ชื่อตัวแปร][value | is_global]
	 * @var array
	 */
	private static $var_list = [];
	/**
	 * เก็บตัวแปรที่สำรองค่าไว้
	 * @var unknown
	 */
	private static $preserve = [];
	/**
	 * ชี้ไปยังสมาชิกตัวท้ายสุดของ $preserve
	 */
	private static $latest_preserve = null;
	/**
	 * ใส่ค่าให้กับตัวแปร
	 * @param int $channel ช่องของตัวแปร
	 * @param mixed $var_name ชื่อของตัวแปร
	 * @param mixed $value ค่าของตัวแปร
	 * @param boolean $global ระบุว่า จะให้ประกาศตัวแปรแบบ global (เข้าถึงได้หมดทั้งเทมเพลต) หรือไม่
	 */
	public static function set_var($channel, $var_name, $value, $global)
	{
		if(!$global && self::$latest_preserve !== null)
		{
			//ถ้าใน preserve ยังไม่มีช่องตัวแปรที่จะเก็บ ให้สร้างใหม่
			if(!array_key_exists($channel, self::$latest_preserve))
			{
				self::$latest_preserve[$channel] = [];
			}
			//ถ้าใน preserve ยังไม่มีตัวแปรชื่อนี้ และไม่ใช่ตัวแปรแบบ global ให้สำรองไว้
			if(!array_key_exists($var_name, self::$latest_preserve[$channel])
				&& !$global)
			{
				//เก็บตัวแปรลงใน preserve ในช่องที่ผู้ใช้ระบุ
				self::$latest_preserve[$channel][$var_name] = self::_get_var_attrs($channel, $var_name);
			}
		}
		self::_set_var($channel, $var_name, $value, $global);
	}
	/**
	 * เข้าสู่ block node ใหม่
	 * ในฟังก์ชันนี้จะสร้างช่องสำหรับสำรองตัวแปรจากบล็อกก่อนหน้านี้
	 */
	public static function enter_block()
	{
		$n = array_push(self::$preserve, []);
		self::$latest_preserve = &self::$preserve[$n-1]; //ชี้ไปที่ตำแหน่งท้ายสุด
	}
	/**
	 * ออกจาก block node ปัจจุบัน
	 * ในฟังก์ชันนี้จะเอาตัวแปรของบล็อกก่อนหน้านี่ที่สำรองไว้ ใส่กลับเข้าไปคืน
	 */
	public static function exit_block()
	{
		$preserve = array_pop(self::$preserve);
		self::$latest_preserve = &self::$preserve[count(self::$preserve)-1]; //ชี้ไปที่ตำแหน่งท้ายสุด
		
		$channels = array_keys($preserve);
		for($i=0; $i<count($channels); $i++) //foreach ($preserve as $channel=>$var_list)
		{
			$channel = $channels[$i];
			$var_list = $preserve[$channel];
			foreach($var_list as $var_name=>$var_attrs)
			{
				if($var_attrs === null)
				{
					unset(self::$var_list[$channel][$var_name]);
				}
				else
				{
					if(self::$var_list[$channel][$var_name]['is_global'] === false)
					{
						self::$var_list[$channel][$var_name] = $var_attrs;
					}
				}
			}
		}
	}
	/**
	 * ใส่ค่าให้ตัวแปร โดยที่ไม่มีการ preserve ตัวแปรจากบล็อกโนดก่อนหน้านี้
	 * @param int $channel
	 * @param mixed $var_name
	 * @param mixed $value
	 * @param boolean $global
	 */
	private static function _set_var($channel, $var_name, $value, $global)
	{
		if(!array_key_exists($channel, self::$var_list))
		{
			self::$var_list[$channel] = [];
		}
		self::$var_list[$channel][$var_name] = ["is_global" => $global, "value" => $value];
	}
	/**
	 * ดึงค่าตัวแปร
	 * @param int $channel ช่องของตัวแปร
	 * @param mixed $var_name ชื่อตัวแปร
	 * @return mixed
	 */
	public static function get_var($channel, $var_name)
	{
		if($a = self::_get_var_attrs($channel, $var_name))
		{
			return $a['value'];
		}
		else
		{
			return null;
		}
	}
	
	private static function _get_var_attrs($channel, $var_name)
	{
		if(array_key_exists($channel, self::$var_list)
				&& array_key_exists($var_name, self::$var_list[$channel]))
		{
			return self::$var_list[$channel][$var_name];
		}
		else
		{
			return null;
		}
	}
	/**
	 * ล้างค่าตัวแปรทั้งหมด
	 */
	public static function reset()
	{
		self::$var_list = [];
		self::$preserve = [];
		self::$latest_preserve = null;
	}
	/**
	 * ล้างค่าเฉพาะตัวแปรในช่อง reserve
	 */
	public static function clear_reserve()
	{
		if(array_key_exists(VAR_CHANNEL_RESERVED, self::$var_list))
		{
			unset(self::$var_list[VAR_CHANNEL_RESERVED]);
		}
	}
	
	public static function _test()
	{
		$already_reset = false;
		test:
		
		//----test #1 : root block variable----
		self::set_var(1, "var1/1", "value1/1", true);
		self::set_var(1, "var1/2", "value1/2", false);
		self::set_var(2, "var2/1", "value2/1", true);
		self::set_var(2, "var2/2", "value2/2", false);
		
		assert(self::get_var(1, "var1/1") === "value1/1"
				&& self::get_var(1, "var1/2") === "value1/2"
				&& self::get_var(2, "var2/1") === "value2/1"
				&& self::get_var(2, "var2/2") === "value2/2"
				, "#1.1");
		
		self::set_var(1, "var1/2", "value1/2 edit", false);
		self::set_var(2, "var2/1", "value2/1 edit", true);
		
		assert(self::get_var(1, "var1/2") === "value1/2 edit"
				&& self::get_var(2, "var2/1") === "value2/1 edit"				
				, "#1.2");
		
		//----test #2 : sub-block variable----
		self::enter_block();
		self::set_var(1, "var1/1", "value1/1 subblock", false);
		self::set_var(2, "var2/2", "value2/2 subblock", true);
		
		assert(self::get_var(1, "var1/1") === "value1/1 subblock"
				&& self::get_var(2, "var2/2") === "value2/2 subblock", "#2.1");
		//----test #3 : sub-sub-block variable----
		self::enter_block();
		self::set_var(1, "var1/1", "value1/1 subsubblock", false);
		self::set_var(1, "var1/1", "value1/1 subsubblock2", false);
		self::set_var(2, "var2/2", "value2/2 subsubblock", false);
		self::set_var(1, "var1/2", "value1/2 subsubblock", true);
		self::set_var(3, "var3/1", "value3/1 subsubblock", false);
		self::set_var(3, "var3/2", "value3/2 subsubblock", true);
		
		assert(self::get_var(1, "var1/1") === "value1/1 subsubblock2"
				&& self::get_var(2, "var2/2") === "value2/2 subsubblock"
				&& self::get_var(1, "var1/2") === "value1/2 subsubblock"
				&& self::get_var(3, "var3/1") === "value3/1 subsubblock"
				&& self::get_var(3, "var3/2") === "value3/2 subsubblock", "#3.1");
		//----test #4 : restore previous block's variable----
		self::exit_block();
		assert(self::get_var(1, "var1/1") === "value1/1 subblock"
				&& self::get_var(2, "var2/2") === "value2/2 subblock"
				&& self::get_var(1, "var1/2") === "value1/2 subsubblock"
				&& self::get_var(3, "var3/1") === null
				&& self::get_var(3, "var3/2") === "value3/2 subsubblock", "#4.1");
		//----test #5 : restore root block's variable----
		self::exit_block();
		assert(self::get_var(1, "var1/1") === "value1/1"
				&& self::get_var(1, "var1/2") === "value1/2 subsubblock"
				&& self::get_var(2, "var2/1") === "value2/1 edit"
				&& self::get_var(2, "var2/2") === "value2/2 subblock"
				, "#5.1");
		
		//----test #6 : reset----
		if($already_reset) return;
		self::reset();
		assert(self::get_var(1, "var1/1") === null
				&& self::get_var(1, "var1/2") === null
				&& self::get_var(2, "var2/1") === null
				&& self::get_var(2, "var2/2") === null
				, "#5.1");
		$already_reset = true;
		goto test;
	}
}