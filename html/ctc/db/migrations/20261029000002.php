<?php


use Phinx\Migration\AbstractMigration;

final class V20261029000002 extends AbstractMigration
{

    const FORMAT_PREFIX = 'v2:';

    /**
     * 历史 .env.default 中的公开占位密钥。
     *
     * 生产兑换码曾使用该值加密；APP_KEY 轮换后需要用它解密一次，
     * 再用当前 APP_KEY 重新加密。该值不是生产密钥。
     */
    const LEGACY_DEFAULT_KEY = 'change_me_to_random_32_chars';

    public function up()
    {
        $config = require __DIR__ . '/../../config/config.php';

        $currentKey = (string)($config['key'] ?? '');

        if ($currentKey === '' || $currentKey === self::LEGACY_DEFAULT_KEY) {
            $this->output->writeln('<comment>Skip: APP_KEY is empty or still the legacy default.</comment>');
            return;
        }

        $connection = $this->getAdapter()->getConnection();

        $rows = $connection->query(
            'SELECT id, code, code_hash FROM kg_vip_redeem_code ORDER BY id FOR UPDATE'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $update = $connection->prepare(
            'UPDATE kg_vip_redeem_code SET code = :code, update_time = :update_time WHERE id = :id'
        );

        $updated = 0;
        $skipped = 0;
        $unresolved = 0;

        foreach ($rows as $row) {
            if ($this->decrypt($row['code'], $currentKey) !== null) {
                $skipped++;
                continue;
            }

            $plainCode = $this->decrypt($row['code'], self::LEGACY_DEFAULT_KEY);

            if ($plainCode === null || !hash_equals($row['code_hash'], hash('sha256', $plainCode))) {
                $unresolved++;
                $this->output->writeln(sprintf('<comment>Unresolved code id=%d</comment>', $row['id']));
                continue;
            }

            $newCode = $this->encrypt($plainCode, $currentKey);

            if ($this->decrypt($newCode, $currentKey) !== $plainCode) {
                throw new \RuntimeException('Re-encrypted code verification failed: id=' . $row['id']);
            }

            $update->execute([
                'code' => $newCode,
                'update_time' => time(),
                'id' => $row['id'],
            ]);

            $updated++;
        }

        $this->output->writeln(sprintf(
            '<info>VIP redeem code key migration: updated=%d skipped=%d unresolved=%d</info>',
            $updated,
            $skipped,
            $unresolved
        ));
    }

    public function down()
    {
        /**
         * 数据修复无需回退；兑换码明文与 code_hash 均未改变。
         */
    }

    protected function decrypt($value, $key)
    {
        if (!is_string($value) || strpos($value, self::FORMAT_PREFIX) !== 0) {
            return null;
        }

        $payload = base64_decode(substr($value, strlen(self::FORMAT_PREFIX)), true);

        if ($payload === false) {
            return null;
        }

        $ivLength = openssl_cipher_iv_length('aes-256-cbc');

        if ($ivLength <= 0 || strlen($payload) <= $ivLength) {
            return null;
        }

        $iv = substr($payload, 0, $ivLength);
        $encrypted = substr($payload, $ivLength);

        $plainCode = openssl_decrypt(
            $encrypted,
            'aes-256-cbc',
            $this->deriveKey($key),
            OPENSSL_RAW_DATA,
            $iv
        );

        if (!is_string($plainCode) || !preg_match('/^[A-Z2-9]{20}$/', $plainCode)) {
            return null;
        }

        return $plainCode;
    }

    protected function encrypt($plainCode, $key)
    {
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');

        $iv = random_bytes($ivLength);

        $encrypted = openssl_encrypt(
            $plainCode,
            'aes-256-cbc',
            $this->deriveKey($key),
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Failed to encrypt VIP redeem code.');
        }

        return self::FORMAT_PREFIX . base64_encode($iv . $encrypted);
    }

    protected function deriveKey($key)
    {
        return hash('sha256', $key, true);
    }

}
