<?php
/**
 * BMC 目标列表的 JSON 文件存储封装
 */
class BmcStore
{
    private $path;

    public function __construct(string $path)
    {
        $this->path = $path;
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!file_exists($this->path)) {
            @file_put_contents($this->path, json_encode(['bmcs' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    public function listAll(): array
    {
        $data = json_decode(file_get_contents($this->path), true);
        return $data['bmcs'] ?? [];
    }

    public function find(int $id): ?array
    {
        foreach ($this->listAll() as $b) {
            if (($b['id'] ?? 0) === $id) return $b;
        }
        return null;
    }

    public function save(array $bmcs): bool
    {
        return file_put_contents(
            $this->path,
            json_encode(['bmcs' => array_values($bmcs)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        ) !== false;
    }

    public function add(array $bmc): array
    {
        $bmcs = $this->listAll();
        $id   = 1;
        foreach ($bmcs as $b) $id = max($id, (int)($b['id'] ?? 0) + 1);
        $bmc['id'] = $id;
        $bmcs[] = $bmc;
        $this->save($bmcs);
        return $bmc;
    }

    public function update(int $id, array $patch): ?array
    {
        $bmcs = $this->listAll();
        foreach ($bmcs as &$b) {
            if ((int)$b['id'] === $id) {
                $b = array_merge($b, $patch, ['id' => $id]);
                $this->save($bmcs);
                return $b;
            }
        }
        return null;
    }

    public function delete(int $id): bool
    {
        $bmcs = $this->listAll();
        $new = array_values(array_filter($bmcs, fn($b) => (int)$b['id'] !== $id));
        if (count($new) === count($bmcs)) return false;
        return $this->save($new);
    }
}
