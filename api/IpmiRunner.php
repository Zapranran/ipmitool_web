<?php
/**
 * 安全执行 ipmitool 命令，支持真实执行和 demo 模拟两种模式
 * 使用 proc_open + 参数数组，彻底避免命令注入
 */
class IpmiRunner
{
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    /**
     * 统一入口：根据是否 demo 模式分流
     */
    public function run(array $bmc, string $subcmd): array
    {
        if ($this->cfg['demo_mode']) {
            return $this->demo($subcmd, $bmc);
        }
        return $this->exec($bmc, $subcmd);
    }

    /**
     * 执行 ipmitool 命令
     */
    private function exec(array $bmc, string $subcmd): array
    {
        $tool  = $this->cfg['ipmitool_path'];
        $port  = $bmc['port'] ?? 623;
        $privl = $bmc['privl'] ?? 'USER';
        $intf  = $bmc['interface'] ?? $this->cfg['default_interface'];

        // 把命令拆成参数数组，proc_open 会直接传给进程，不经过 shell
        $args = [
            $tool,
            '-I', $intf,
            '-H', $bmc['host'],
            '-p', (string)$port,
            '-U', $bmc['user'],
            '-P', $bmc['pass'],
            '-L', $privl,
        ];

        // 解析子命令字符串为参数数组（按空格拆分即可，IPMI 命令都不含空格参数）
        foreach (preg_split('/\s+/', trim($subcmd)) as $tok) {
            if ($tok !== '') $args[] = $tok;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = $_ENV;
        if (isset($env['SystemRoot'])) unset($env['SystemRoot']); // Windows 安全
        $env['LANG'] = 'C';

        $pipes = [];
        $proc = @proc_open($args, $descriptors, $pipes, null, $env);

        if (!is_resource($proc)) {
            return [
                'ok'    => false,
                'code'  => -1,
                'stdout'=> '',
                'stderr'=> '无法启动 ipmitool 进程，请确认路径：' . $tool,
            ];
        }

        stream_set_timeout($pipes[1], $this->cfg['timeout']);
        stream_set_timeout($pipes[2], $this->cfg['timeout']);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]);

        $code = proc_close($proc);

        return [
            'ok'     => $code === 0,
            'code'   => $code,
            'stdout' => rtrim($stdout, "\r\n"),
            'stderr' => rtrim($stderr, "\r\n"),
        ];
    }

    /**
     * 模拟数据（demo 模式）— 让前端界面可以完整跑通
     */
    private function demo(string $subcmd, array $bmc): array
    {
        $host = $bmc['host'] ?? '192.168.1.100';
        $cmd  = strtolower(trim($subcmd));

        // 电源状态
        if (strpos($cmd, 'chassis power status') === 0) {
            return ['ok' => true, 'code' => 0, 'stdout' => 'Chassis Power is on', 'stderr' => ''];
        }
        if (strpos($cmd, 'chassis power on') === 0) {
            return ['ok' => true, 'code' => 0, 'stdout' => 'Chassis Power Control: Up/On', 'stderr' => ''];
        }
        if (strpos($cmd, 'chassis power off') === 0) {
            return ['ok' => true, 'code' => 0, 'stdout' => 'Chassis Power Control: Down/Off', 'stderr' => ''];
        }
        if (strpos($cmd, 'chassis power cycle') === 0) {
            return ['ok' => true, 'code' => 0, 'stdout' => 'Chassis Power Control: Cycle', 'stderr' => ''];
        }
        if (strpos($cmd, 'chassis power reset') === 0) {
            return ['ok' => true, 'code' => 0, 'stdout' => 'Chassis Power Control: Reset', 'stderr' => ''];
        }
        if (strpos($cmd, 'chassis status') === 0) {
            $lines = [
                'System Power         : on',
                'Power Overload       : false',
                'Power Interlock      : inactive',
                'Main Power Fault     : false',
                'Power Control Fault  : false',
                'Power Restore Policy : always-off',
                'Last Power Event     : command',
                'Chassis Intrusion    : active',
                'Front-Panel Lockout  : inactive',
                'Drive Fault          : false',
                'Cooling/Fan Fault    : false',
            ];
            return ['ok' => true, 'code' => 0, 'stdout' => implode("\n", $lines), 'stderr' => ''];
        }

        // 传感器
        if (strpos($cmd, 'sdr type temperature') === 0 || strpos($cmd, 'sensor') === 0) {
            $lines = [
                'Inlet Temp       | 24h | ok  |  3.1 | 24 degrees C',
                'CPU Temp         | 34h | ok  |  3.2 | 48 degrees C',
                'DIMM Temp        | 35h | ok  |  3.3 | 42 degrees C',
                'Exhaust Temp     | 30h | ok  |  3.4 | 36 degrees C',
            ];
            return ['ok' => true, 'code' => 0, 'stdout' => implode("\n", $lines), 'stderr' => ''];
        }
        if (strpos($cmd, 'sdr type voltage') === 0) {
            $lines = [
                'CPU Vcore        | 30h | ok  |  3.1 | 1.20 Volts',
                '1.5V PCH         | 31h | ok  |  3.2 | 1.52 Volts',
                '3.3V             | 32h | ok  |  3.3 | 3.31 Volts',
                '5V               | 33h | ok  |  3.4 | 5.02 Volts',
                '12V              | 34h | ok  |  3.5 | 12.05 Volts',
            ];
            return ['ok' => true, 'code' => 0, 'stdout' => implode("\n", $lines), 'stderr' => ''];
        }
        if (strpos($cmd, 'sdr type fan') === 0) {
            $lines = [
                'Fan1             | 30h | ok  |  7.1 | 3200 RPM',
                'Fan2             | 31h | ok  |  7.2 | 3150 RPM',
                'Fan3             | 32h | ok  |  7.3 | 3300 RPM',
            ];
            return ['ok' => true, 'code' => 0, 'stdout' => implode("\n", $lines), 'stderr' => ''];
        }

        // SEL
        if (strpos($cmd, 'sel') === 0) {
            $lines = [
                '  1 | Pre-init     | 2025-10-20 08:30:00 | #0x01 | System AC Power',
                '  2 | Pre-init     | 2025-10-20 08:30:00 | #0x02 | System Power',
                '  3 | Pre-init     | 2025-10-20 08:31:12 | #0x03 | Watchdog Timer',
                '  4 | System       | 2025-10-25 14:22:05 | #0x04 | Temperature Upper Critical - going high',
                '  5 | System       | 2025-10-26 09:15:33 | #0x05 | Fan Failure detected',
            ];
            return ['ok' => true, 'code' => 0, 'stdout' => implode("\n", $lines), 'stderr' => ''];
        }

        // FRU
        if (strpos($cmd, 'fru') === 0) {
            $lines = [
                'Product Manufacturer    : Supermicro',
                'Product Name            : SYS-1029P-WTR',
                'Product Serial Number   : SMR23A001234',
                'Product Part Number     : PIO-1029P-WTR',
                'Product Version         : REV 1.00',
                'Board Mfg Date          : 10/15/2024',
                'Board Serial            : BDF24005678',
                'Board Part Number       : BDF-0000',
                'Chassis Serial          : CSM23009876',
            ];
            return ['ok' => true, 'code' => 0, 'stdout' => implode("\n", $lines), 'stderr' => ''];
        }

        // raw
        if (strpos($cmd, 'raw') === 0) {
            return ['ok' => true, 'code' => 0, 'stdout' => ' 00 01 02 03 04 05 06 07', 'stderr' => ''];
        }

        // 默认：返回一些提示
        return [
            'ok' => true,
            'code' => 0,
            'stdout' => "[DEMO] host=$host cmd=$cmd\n（无真实 ipmitool 环境，此为模拟输出。在 config.php 中关闭 demo_mode 并安装 ipmitool 即可切换为真实模式。）",
            'stderr' => '',
        ];
    }
}
