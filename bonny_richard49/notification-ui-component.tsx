import React, { useState, useEffect } from 'react';
import { Bell, Settings, X, Check, ExternalLink } from 'lucide-react';
import { 
  Alert,
  AlertDescription,
  AlertTitle
} from '@/components/ui/alert';

const NotificationCenter = () => {
  const [notifications, setNotifications] = useState([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [isOpen, setIsOpen] = useState(false);

  useEffect(() => {
    fetchNotifications();
    const interval = setInterval(fetchNotifications, 30000);
    return () => clearInterval(interval);
  }, []);

  const fetchNotifications = async () => {
    try {
      const response = await fetch('/api/notifications');
      const data = await response.json();
      setNotifications(data.data);
      setUnreadCount(data.meta.unread_count);
    } catch (error) {
      console.error('Failed to fetch notifications:', error);
    }
  };

  const markAsRead = async (id) => {
    try {
      await fetch(`/api/notifications/${id}/mark-read`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
      });
      fetchNotifications();
    } catch (error) {
      console.error('Failed to mark notification as read:', error);
    }
  };

  return (
    <div className="relative">
      {/* Notification Button */}
      <button 
        className="relative p-2 rounded-lg hover:bg-gray-100"
        onClick={() => setIsOpen(!isOpen)}
      >
        <Bell className="w-6 h-6" />
        {unreadCount > 0 && (
          <span className="absolute top-0 right-0 flex items-center justify-center w-5 h-5 text-xs text-white bg-red-500 rounded-full">
            {unreadCount}
          </span>
        )}
      </button>

      {/* Notification Panel */}
      {isOpen && (
        <div className="absolute right-0 w-96 mt-2 bg-white rounded-lg shadow-lg overflow-hidden z-50">
          {/* Header */}
          <div className="flex items-center justify-between p-4 border-b">
            <h2 className="text-lg font-semibold">Notifications</h2>
            <div className="flex gap-2">
              <button className="p-2 hover:bg-gray-100 rounded-lg">
                <Settings className="w-5 h-5" />
              </button>
              <button 
                className="p-2 hover:bg-gray-100 rounded-lg"
                onClick={() => setIsOpen(false)}
              >
                <X className="w-5 h-5" />
              </button>
            </div>
          </div>

          {/* Notification List */}
          <div className="max-h-96 overflow-y-auto">
            {notifications.length === 0 ? (
              <div className="p-4 text-center text-gray-500">
                No notifications
              </div>
            ) : (
              notifications.map((notification) => (
                <NotificationItem 
                  key={notification.id}
                  notification={notification}
                  onMarkAsRead={markAsRead}
                />
              ))
            )}
          </div>
        </div>
      )}
    </div>
  );
};

const NotificationItem = ({ notification, onMarkAsRead }) => {
  const { id, data, read_at } = notification;
  const isUnread = !read_at;

  const getNotificationIcon = () => {
    switch (data.type) {
      case 'success':
        return <Check className="w-5 h-5 text-green-500" />;
      case 'warning':
        return <AlertCircle className="w-5 h-5 text-yellow-500" />;
      case 'error':
        return <AlertTriangle className="w-5 h-5 text-red-500" />;
      default:
        return <Bell className="w-5 h-5 text-blue-500" />;
    }
  };

  return (
    <div className={`p-4 border-b hover:bg-gray-50 ${isUnread ? 'bg-blue-50' : ''}`}>
      <div className="flex gap-3">
        <div className="flex-shrink-0">
          {getNotificationIcon()}
        </div>
        <div className="flex-1 min-w-0">
          <p className="text-sm font-medium text-gray-900">
            {data.title}
          </p>
          <p className="mt-1 text-sm text-gray-500">
            {data.message}
          </p>
          {data.action_url && (
            <a 
              href={data.action_url}
              className="mt-2 inline-flex items-center text-sm text-blue-600 hover:text-blue-800"
            >
              {data.action_text || 'View Details'}
              <ExternalLink className="ml-1 w-4 h-4" />
            </a>
          )}
          <div className="mt-2 flex items-center justify-between">
            <span className="text-xs text-gray-500">
              {new Date(notification.created_at).toLocaleTimeString()}
            </span>
            {isUnread && (
              <button
                className="text-xs text-blue-600 hover:text-blue-800"
                onClick={() => onMarkAsRead(id)}
              >
                Mark as read
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

const NotificationPreferences = () => {
  const [preferences, setPreferences] = useState({});
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchPreferences();
  }, []);

  const fetchPreferences = async () => {
    try {
      const response = await fetch('/api/notification-preferences');
      const data = await response.json();
      setPreferences(data);
      setLoading(false);
    } catch (error) {
      console.error('Failed to fetch preferences:', error);
      setLoading(false);
    }
  };

  const updatePreference = async (channel, enabled) => {
    try {
      await fetch(`/api/notification-preferences/channels/${channel}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ enabled })
      });
      fetchPreferences();
    } catch (error) {
      console.error('Failed to update preference:', error);
    }
  };

  if (loading) {
    return <div>Loading preferences...</div>;
  }

  return (
    <div className="max-w-2xl mx-auto p-6">
      <h2 className="text-2xl font-bold mb-6">Notification Preferences</h2>
      <div className="space-y-4">
        {Object.entries(preferences).map(([channel, settings]) => (
          <div key={channel} className="flex items-center justify-between p-4 bg-white rounded-lg shadow">
            <div>
              <h3 className="text-lg font-medium">{channel}</h3>
              <p className="text-sm text-gray-500">{settings.description}</p>
            </div>
            <label className="relative inline-flex items-center cursor-pointer">
              <input
                type="checkbox"
                className="sr-only peer"
                checked={settings.enabled}
                onChange={(e) => updatePreference(channel, e.target.checked)}
              />
              <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
            </label>
          </div>
        ))}
      </div>
    </div>
  );
};

export { NotificationCenter, NotificationPreferences };