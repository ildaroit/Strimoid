<?php namespace Strimoid\Models;

use Auth, App, Image;
use Strimoid\Helpers\MarkdownParser;

/**
 * Group model
 *
 * @property string $_id
 * @property string $name Group name
 * @property string $description Group description
 * @property string $avatar Group avatar filename
 * @property string $sidebar Sidebar text
 * @property DateTime $created_at
 */
class Group extends BaseModel
{

    protected $attributes = [
        'subscribers' => 0,
        'type' => self::TYPE_PUBLIC,
    ];

    protected $table = 'groups';
    protected $visible = [
        'id', 'avatar', 'created_at', 'creator',
        'description', 'sidebar', 'subscribers', 'name'
    ];

    const TYPE_PUBLIC = 0;
    const TYPE_MODERATED = 1;
    const TYPE_PRIVATE = 2;
    const TYPE_ANNOUNCEMENTS  = 99;

    public function creator()
    {
        return $this->belongsTo('Strimoid\Models\User');
    }

    public function entries()
    {
        $relation = $this->hasMany('Strimoid\Models\Entry');

        if (Auth::check())
        {
            $blockedUsers = Auth::user()->blockedUsers();
            $relation->whereNotIn('user_id', (array) $blockedUsers);
        }

        return $relation;
    }

    public function comments()
    {
        $relation = $this->hasMany('Strimoid\Models\Comment');

        if (Auth::check())
        {
            $blockedUsers = Auth::user()->blockedUsers();
            $relation->whereNotIn('user_id', (array) $blockedUsers);
        }

        return $relation;
    }

    public function contents()
    {
        $relation = $this->hasMany('Strimoid\Models\Content');

        if (Auth::check())
        {
            $blockedUsers = Auth::user()->blockedUsers();
            $relation->whereNotIn('user_id', $blockedUsers);

            $blockedDomains = Auth::user()->blocked_domains;
            $relation->whereNotIn('domain', $blockedDomains);
        }

        return $relation;
    }

    public function moderators()
    {
        return $this->hasMany('Strimoid\Models\GroupModerator');
    }

    public function checkAccess()
    {
        if ($this->type == Group::TYPE_PRIVATE)
        {
            if (!Auth::check() || !Auth::user()->isModerator($this))
            {
                App::abort(403, 'Access denied');
            }
        }
    }

    public function delete()
    {
        $this->deleteAvatar();
        $this->deleteStyle();

        return parent::delete();
    }

    public function getAvatarPath()
    {
        if ($this->avatar)
        {
            return '/uploads/groups/'. $this->avatar;
        }
        else
        {
            return '/static/img/default_avatar.png';
        }
    }

    public function setAvatar($file)
    {
        $this->deleteAvatar();

        $filename = Str::random(8) .'.png';

        $img = Image::make($file);
        $img->fit(100, 100);
        $img->save(Config::get('app.uploads_path').'/groups/'. $filename);

        $this->avatar = $filename;
    }

    public function deleteAvatar()
    {
        if ($this->avatar)
        {
            File::delete(Config::get('app.uploads_path').'/avatars/'. $this->avatar);

            $this->unset('avatar');
        }
    }

    public function setStyle($css)
    {
        // Compatibility with old saving method
        if (file_exists(Config::get('app.uploads_path').'/styles/'. Str::lower($this->urlname) .'.css'))
        {
            File::delete(Config::get('app.uploads_path').'/styles/'. Str::lower($this->urlname) .'.css');
        }

        $this->deleteStyle();

        if ($css)
        {
            $this->style = $this->shadow_urlname .'.'. Str::random(8) .'.css';

            File::put(Config::get('app.uploads_path').'/styles/'. $this->style, $css);
        }
    }

    public function deleteStyle()
    {
        if ($this->style)
        {
            File::delete(Config::get('app.uploads_path').'/styles/'. $this->style);
            $this->unset('style');
        }
    }

    public function banUser(User $user, $reason = '')
    {
        if ($user->isBanned($this))
        {
            return false;
        }

        $ban = new GroupBanned();

        $ban->group()->associate($this);
        $ban->user()->associate($user);
        $ban->moderator()->associate(Auth::user());
        $ban->reason = Input::get('reason');

        $ban->save();
    }

    public function getAvatarPathAttribute()
    {
        $host = Config::get('app.cdn_host');

        if ($this->avatar)
        {
            return $host .'/groups/'. $this->avatar;
        }
        else
        {
            return $host .'/static/img/default_avatar.png';
        }
    }

    public function setSidebarAttribute($text)
    {
        $this->attributes['sidebar'] = MarkdownParser::instance()->text(parse_usernames($text));
        $this->attributes['sidebar_source'] = $text;
    }

    public function setURLNameAttribute($text)
    {
        $this->attributes['urlname'] = $text;
        $this->attributes['shadow_urlname'] = shadow($text);
    }

    /* Scopes */

    public function scopeShadow($query, $name)
    {
        return $query->where('shadow_urlname', shadow($name));
    }

}