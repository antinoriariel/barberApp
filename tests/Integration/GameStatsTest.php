<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Models\GameScore;
use App\Models\GameSession;
use App\Models\User;

/**
 * Tiempo de juego y ranking de los minijuegos (Models\GameSession, Models\GameScore): el tiempo lo mide el servidor,
 * los puntajes imposibles no entran al ranking, los tokens sirven una vez y las filas alteradas en la base se ignoran.
 */
final class GameStatsTest extends DbTestCase
{
    private array $games;

    protected function setUp(): void
    {
        parent::setUp();
        $this->games = (require BASE_PATH . '/config/games.php')['games'];
        Database::execute("DELETE FROM game_highscores WHERE game IN ('flappy', 'runner', 'pacman')");
    }

    /** Abre una partida y simula que empezó hace $ago segundos. */
    private function openAgo(User $u, string $game, int $ago): string
    {
        $token = GameSession::open($u, $game, 3600);
        Database::execute('UPDATE game_sessions SET started_at = ? WHERE token_hash = ?',
            [date('Y-m-d H:i:s', time() - $ago), hash('sha256', $token)]);
        return $token;
    }

    /** Juega una partida completa de $seconds segundos con $score puntos. */
    private function play(User $u, string $game, int $seconds, int $score): ?array
    {
        return GameSession::finish($u, $game, $this->openAgo($u, $game, $seconds), $score, $this->games[$game], 3600);
    }

    public function testServerMeasuresTimeAndSumsItPerGame(): void
    {
        $u = $this->makeUser('99300001');
        $this->play($u, 'flappy', 60, 10);
        $this->play($u, 'flappy', 30, 5);
        $this->play($u, 'runner', 40, 100);

        $spent = GameSession::timeSpent($u->id);
        $this->assertEqualsWithDelta(90, $spent['flappy'], 2);
        $this->assertEqualsWithDelta(40, $spent['runner'], 2);
        $this->assertArrayNotHasKey('pacman', $spent);
    }

    public function testSessionTimeIsCappedAndAbandonedRunsAreClosed(): void
    {
        $u = $this->makeUser('99300002');
        $this->openAgo($u, 'runner', 99999);       // pestaña olvidada
        $this->play($u, 'flappy', 10, 3);           // al abrir otra, la vieja se cierra como abandonada

        $row = Database::selectOne("SELECT status, seconds, score FROM game_sessions WHERE user_id = ? AND game = 'runner'", [$u->id]);
        $this->assertSame('abandoned', $row['status']);
        $this->assertSame(3600, (int) $row['seconds']);
        $this->assertSame(0, (int) $row['score']);
    }

    public function testImpossibleScoreIsRejectedAndNotRanked(): void
    {
        $u = $this->makeUser('99300003');
        $res = $this->play($u, 'flappy', 5, 500);   // 500 peines en 5 segundos
        $this->assertSame('rejected', $res['status']);
        $this->assertSame(0, GameScore::best($u->id, 'flappy'));

        $this->assertSame('rejected', $this->play($u, 'pacman', 600, 2605)['status']);  // no es múltiplo de 10
        $this->assertSame('rejected', $this->play($u, 'pacman', 3000, 20000)['status']); // supera el máximo posible
        $this->assertSame('done', $this->play($u, 'pacman', 120, 2600)['status']);
    }

    public function testTokenIsSingleUseAndBoundToUserAndGame(): void
    {
        $a = $this->makeUser('99300004');
        $b = $this->makeUser('99300005');
        $token = $this->openAgo($a, 'runner', 30);

        $this->assertNull(GameSession::finish($b, 'runner', $token, 10, $this->games['runner'], 3600)); // ajeno
        $this->assertNull(GameSession::finish($a, 'flappy', $token, 1, $this->games['flappy'], 3600));  // otro juego
        $this->assertSame('done', GameSession::finish($a, 'runner', $token, 10, $this->games['runner'], 3600)['status']);
        $this->assertNull(GameSession::finish($a, 'runner', $token, 900, $this->games['runner'], 3600)); // repetido
        $this->assertNull(GameSession::finish($a, 'runner', str_repeat('0', 64), 1, $this->games['runner'], 3600));
    }

    public function testRankingKeepsBestPerUserAndShowsTopSix(): void
    {
        $users = [];
        for ($i = 0; $i < 8; $i++) {
            $users[$i] = $this->makeUser('9930010' . $i);
            $this->play($users[$i], 'runner', 60, 100 + $i * 10);
        }
        $this->play($users[0], 'runner', 60, 1000); // el último pasa a ser el primero
        $this->play($users[0], 'runner', 60, 50);   // un puntaje peor no baja su récord

        $top = GameScore::top('runner', 6, $users[0]);
        $this->assertCount(6, $top);
        $this->assertSame([1000, 170, 160, 150, 140, 130], array_column($top, 'score'));
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($top, 'rank'));
        $this->assertTrue($top[0]['me']);
        $this->assertFalse($top[1]['me']);
        $this->assertSame('Prueba 9.', $top[0]['name']); // nombre de pila + inicial del apellido
    }

    public function testTamperedRowsAreIgnored(): void
    {
        $u = $this->makeUser('99300006');
        $v = $this->makeUser('99300007');
        $this->play($u, 'runner', 60, 300);
        $this->play($v, 'runner', 60, 200);

        // Alguien con acceso a la base se sube el récord y el tiempo a mano.
        Database::execute("UPDATE game_highscores SET score = 999999 WHERE user_id = ? AND game = 'runner'", [$v->id]);
        Database::execute("UPDATE game_sessions SET seconds = 50000 WHERE user_id = ? AND game = 'runner'", [$u->id]);

        $scores = array_column(GameScore::top('runner', 6), 'score');
        $this->assertSame([300], $scores);
        $this->assertSame(0, GameScore::best($v->id, 'runner'));
        $this->assertArrayNotHasKey('runner', GameSession::timeSpent($u->id));

        // El próximo puntaje válido reemplaza la fila alterada.
        $this->play($v, 'runner', 60, 150);
        $this->assertSame(150, GameScore::best($v->id, 'runner'));
    }

    public function testRewardCanBeClaimedOncePerRun(): void
    {
        $u = $this->makeUser('99300008');
        $token = $this->openAgo($u, 'pacman', 120);
        GameSession::finish($u, 'pacman', $token, 2600, $this->games['pacman'], 3600);

        $first = Database::transaction(fn () => GameSession::claimable($u, 'pacman', $token));
        $this->assertSame(2600, $first['score']);
        $this->assertNull(Database::transaction(fn () => GameSession::claimable($u, 'pacman', $token)));
    }

    public function testPublicNameHidesSurnameAndIsPlainText(): void
    {
        $this->assertSame('Ana P.', GameScore::publicName('Ana María Pérez'));
        $this->assertSame('Juan', GameScore::publicName('  Juan '));
        $this->assertSame('Jugador', GameScore::publicName(''));
        $this->assertSame('<b>x</b> Y.', GameScore::publicName('<b>x</b> y')); // se escapa al mostrarlo, no acá
    }
}
