import { useState, useEffect } from 'react';
import { AlertCircle, Save, Trash, Edit, FileText } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';

const AdminPanel = () => {
  const [content, setContent] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    fetchContent();
  }, []);

  const fetchContent = async () => {
    try {
      const response = await fetch('/api/content');
      const data = await response.json();
      setContent(data);
    } catch (err) {
      setError('Failed to load content');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="w-full max-w-7xl mx-auto px-4">
      <div className="grid gap-6 my-6">
        {error && (
          <Alert variant="destructive">
            <AlertCircle className="h-4 w-4" />
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        <div className="grid gap-4">
          <div className="flex justify-between items-center">
            <h2 className="text-2xl font-bold">Content Management</h2>
            <button 
              onClick={() => window.location.href='/content/new'}
              className="bg-blue-600 text-white px-4 py-2 rounded">
              New Content
            </button>
          </div>
          
          <div className="border rounded-lg shadow">
            {loading ? (
              <div className="p-8 text-center">Loading...</div>
            ) : (
              <ContentList 
                items={content}
                onDelete={id => fetchContent()}
                onEdit={id => window.location.href=`/content/${id}/edit`}
              />
            )}
          </div>
        </div>

        <div className="grid gap-4">
          <div className="flex justify-between items-center">
            <h2 className="text-2xl font-bold">Media Library</h2>
            <MediaUpload onUpload={() => fetchContent()} />
          </div>
          
          <MediaGrid items={content.filter(c => c.type === 'media')} />
        </div>
      </div>
    </div>
  );
};

const ContentList = ({ items, onDelete, onEdit }) => (
  <div className="divide-y">
    {items.map(item => (
      <div key={item.id} className="p-4 flex justify-between items-center">
        <div>
          <h3 className="font-medium">{item.title}</h3>
          <p className="text-sm text-gray-500">{item.status}</p>
        </div>
        <div className="flex gap-2">
          <button onClick={() => onEdit(item.id)} className="p-2 hover:bg-gray-100 rounded">
            <Edit className="h-4 w-4" />
          </button>
          <button onClick={() => onDelete(item.id)} className="p-2 hover:bg-gray-100 rounded text-red-600">
            <Trash className="h-4 w-4" />
          </button>
        </div>
      </div>
    ))}
  </div>
);

const MediaUpload = ({ onUpload }) => {
  const [uploading, setUploading] = useState(false);
  
  const handleUpload = async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    
    setUploading(true);
    const formData = new FormData();
    formData.append('file', file);
    
    try {
      await fetch('/api/media', {
        method: 'POST',
        body: formData
      });
      onUpload();
    } catch (err) {
      setError('Upload failed');
    } finally {
      setUploading(false);
    }
  };
  
  return (
    <div>
      <input 
        type="file" 
        onChange={handleUpload}
        disabled={uploading}
        className="hidden"
        id="media-upload"
      />
      <label 
        htmlFor="media-upload"
        className="bg-blue-600 text-white px-4 py-2 rounded cursor-pointer">
        {uploading ? 'Uploading...' : 'Upload Media'}
      </label>
    </div>
  );
};

const MediaGrid = ({ items }) => (
  <div className="grid grid-cols-4 gap-4">
    {items.map(item => (
      <div key={item.id} className="relative group border rounded-lg overflow-hidden">
        <img 
          src={item.url} 
          alt={item.name}
          className="w-full aspect-square object-cover"
        />
        <div className="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
          <button className="text-white">
            <FileText className="h-6 w-6" />
          </button>
        </div>
      </div>
    ))}
  </div>
);

const ContentForm = ({ content, onSave }) => {
  const [data, setData] = useState(content || {});
  const [saving, setSaving] = useState(false);
  
  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    
    try {
      await onSave(data);
    } finally {
      setSaving(false);
    }
  };
  
  return (
    <form onSubmit={handleSubmit} className="grid gap-4">
      <div className="grid gap-2">
        <label htmlFor="title" className="font-medium">Title</label>
        <input
          id="title"
          value={data.title || ''}
          onChange={e => setData({...data, title: e.target.value})}
          className="border rounded p-2"
          required
        />
      </div>
      
      <div className="grid gap-2">
        <label htmlFor="content" className="font-medium">Content</label>
        <textarea
          id="content"
          value={data.content || ''}
          onChange={e => setData({...data, content: e.target.value})}
          className="border rounded p-2 min-h-[200px]"
          required
        />
      </div>
      
      <button
        type="submit"
        disabled={saving}
        className="bg-blue-600 text-white px-4 py-2 rounded flex items-center justify-center gap-2">
        <Save className="h-4 w-4" />
        {saving ? 'Saving...' : 'Save'}
      </button>
    </form>
  );
};

export default AdminPanel;
