<?php
/**
 * NOTICE OF LICENSE.
 *
 * UNIT3D Community Edition is open-sourced software licensed under the GNU Affero General Public License v3.0
 * The details is bundled with this project in the file LICENSE.txt.
 *
 * @project    UNIT3D Community Edition
 *
 * @author     HDVinnie <hdinnovations@protonmail.com>
 * @license    https://www.gnu.org/licenses/agpl-3.0.en.html/ GNU Affero General Public License v3.0
 */

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Redis;
use Exception;

class Peer extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'active'      => 'boolean',
        'seeder'      => 'boolean',
        'connectable' => 'boolean',
    ];

    /**
     * Prepare a date for array / JSON serialization.
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Belongs To A User.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, self>
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class)->withDefault([
            'username' => 'System',
            'id'       => '1',
        ]);
    }

    /**
     * Belongs To A Torrent.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Torrent, self>
     */
    public function torrent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Torrent::class);
    }

    /**
     * Belongs To A Seed.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Torrent, self>
     */
    public function seed(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Torrent::class, 'torrents.id', 'torrent_id');
    }

    /**
     * Updates Connectable State If Needed.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws Exception
     */
    public function updateConnectableStateIfNeeded(): void
    {
        if (config('announce.connectable_check')) {
            $tmp_ip = inet_ntop(pack('A'.\strlen($this->ip), $this->ip));

            // IPv6 Check
            if (filter_var($tmp_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $tmp_ip = '['.$tmp_ip.']';
            }

            $key = config('cache.prefix').':peers:connectable:'.$tmp_ip.'-'.$this->port.'-'.$this->agent;
            $cache = Redis::connection('cache')->get($key);
            $ttl = 0;

            if ($cache) {
                $ttl = Redis::connection('cache')->command('TTL', [$key]);
            }

            if ($ttl < config('announce.connectable_check_interval')) {
                $con = @fsockopen($tmp_ip, $this->port, $_, $_, 1);
                $this->connectable = \is_resource($con);
                Redis::connection('cache')->set($key, serialize($this->connectable));
                Redis::connection('cache')->expire($key, config('announce.connectable_check_interval') + 3600);

                if (\is_resource($con)) {
                    fclose($con);
                }
            } else {
                $this->connectable = $cache == null ? false : unserialize($cache);
            }
        } else {
            $this->connectable = false;
        }
    }
}
