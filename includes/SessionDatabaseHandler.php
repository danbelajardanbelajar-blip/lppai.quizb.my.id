<?php
class SessionDatabaseHandler implements SessionHandlerInterface {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    #[\ReturnTypeWillChange]
    public function open($path, $name) {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function close() {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function read($id) {
        $stmt = $this->pdo->prepare("SELECT data FROM sessions WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return $row['data'];
        }
        return '';
    }

    #[\ReturnTypeWillChange]
    public function write($id, $data) {
        $stmt = $this->pdo->prepare("REPLACE INTO sessions (id, data, timestamp) VALUES (:id, :data, NOW())");
        return $stmt->execute(['id' => $id, 'data' => $data]);
    }

    #[\ReturnTypeWillChange]
    public function destroy($id) {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }

    #[\ReturnTypeWillChange]
    public function gc($max_lifetime) {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE timestamp < DATE_SUB(NOW(), INTERVAL :max_lifetime SECOND)");
        $stmt->execute(['max_lifetime' => $max_lifetime]);
        return $stmt->rowCount();
    }
}
