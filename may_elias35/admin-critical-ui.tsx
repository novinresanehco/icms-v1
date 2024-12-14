import React, { useState, useEffect } from 'react';
import { AlertCircle, Save, Trash2, Upload } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

const AdminDashboard = () => {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [content, setContent] = useState([]);

  const handleCreate = async (data) => {
    setLoading(true);
    try {
      const response = await fetch('/api/content', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      });
      if (!response.ok) throw new Error('Failed to create content');
      setContent([...content, await response.json()]);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="w-full max-w-6xl mx-auto p-4">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold">Content Management</h1>
        <div className="space-x-2">
          <button
            onClick={() => handleCreate({})}
            disabled={loading}
            className="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700"
          >
            Create New
          </button>
        </div>
      </div>

      {error && (
        <Alert variant="destructive" className="mb-4">
          <AlertCircle className="h-4 w-4" />
          <AlertTitle>Error</AlertTitle>
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      <ContentList content={content} />
      <MediaUpload />
    </div>
  );
};

const ContentList = ({ content }) => (
  <div className="bg-white shadow rounded-lg">
    {content.map(item => (
      <ContentItem key={item.id} item={item} />
    ))}
  </div>
);

const ContentItem = ({ item }) => {
  const [editing, setEditing] = useState(false);
  const [data, setData] = useState(item);

  const handleSave = async () => {
    try {
      await fetch(`/api/content/${item.id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      });
      setEditing(false);
    } catch (err) {
      console.error(err);
    }
  };

  return (
    <div className="border-b p-4">
      {editing ? (
        <div className="space-y-4">
          <input
            type="text"
            value={data.title}
            onChange={e => setData({ ...data, title: e.target.value })}
            className="w-full p-2 border rounded"
          />
          <textarea
            value={data.content}
            onChange={e => setData({ ...data, content: e.target.value })}
            className="w-full p-2 border rounded"
            rows={4}
          />
          <div className="flex justify-end space-x-2">
            <button
              onClick={() => setEditing(false)}
              className="px-4 py-2 border rounded"
            >
              Cancel
            </button>
            <button
              onClick={handleSave}
              className="px-4 py-2 bg-blue-600 text-white rounded"
            >
              <Save className="w-4 h-4 mr-2" />
              Save
            </button>
          </div>
        </div>
      ) : (
        <div className="flex justify-between items-start">
          <div>
            <h3 className="font-medium">{data.title}</h3>
            <p className="text-gray-600">{data.content}</p>
          </div>
          <div className="flex space-x-2">
            <button
              onClick={() => setEditing(true)}
              className="p-2 hover:bg-gray-100 rounded"
            >
              Edit
            </button>
            <button className="p-2 text-red-600 hover:bg-red-50 rounded">
              <Trash2 className="w-4 h-4" />
            </button>
          </div>
        </div>
      )}
    </div>
  );
};

const MediaUpload = () => {
  const handleUpload = async (event) => {
    const file = event.target.files[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('file', file);

    try {
      await fetch('/api/media/upload', {
        method: 'POST',
        body: formData
      });
    } catch (err) {
      console.error(err);
    }
  };

  return (
    <div className="mt-6 p-4 border-2 border-dashed rounded-lg">
      <div className="text-center">
        <Upload className="mx-auto h-12 w-12 text-gray-400" />
        <div className="mt-4">
          <label className="cursor-pointer bg-blue-600 text-white px-4 py-2 rounded">
            <span>Upload Media</span>
            <input
              type="file"
              className="hidden"
              onChange={handleUpload}
              accept="image/*"
            />
          </label>
        </div>
      </div>
    </div>
  );
};

export default AdminDashboard;
