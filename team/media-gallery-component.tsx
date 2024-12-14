import React, { useState, useCallback } from 'react';
import { Camera, Grid, List, Maximize2, Minimize2 } from 'lucide-react';
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert';

const MediaGallery = () => {
  const [view, setView] = useState('grid');
  const [selectedMedia, setSelectedMedia] = useState(null);
  const [error, setError] = useState(null);

  // Simulated secure media data
  const mediaItems = [
    { id: 1, type: 'image', url: '/api/placeholder/400/300', title: 'Image 1', secure: true },
    { id: 2, type: 'image', url: '/api/placeholder/400/300', title: 'Image 2', secure: true },
    { id: 3, type: 'image', url: '/api/placeholder/400/300', title: 'Image 3', secure: true },
    { id: 4, type: 'image', url: '/api/placeholder/400/300', title: 'Image 4', secure: true }
  ];

  const handleMediaSelect = useCallback((media) => {
    if (!media.secure) {
      setError('Security validation failed for media item');
      return;
    }
    setSelectedMedia(media);
    setError(null);
  }, []);

  const toggleView = useCallback(() => {
    setView(current => current === 'grid' ? 'list' : 'grid');
  }, []);

  return (
    <div className="w-full max-w-4xl mx-auto p-4">
      {error && (
        <Alert variant="destructive" className="mb-4">
          <AlertTitle>Error</AlertTitle>
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      <div className="flex justify-between items-center mb-4">
        <h2 className="text-2xl font-semibold">Media Gallery</h2>
        <div className="flex gap-2">
          <button
            onClick={toggleView}
            className="p-2 rounded hover:bg-gray-100 dark:hover:bg-gray-800"
          >
            {view === 'grid' ? <List className="h-5 w-5" /> : <Grid className="h-5 w-5" />}
          </button>
        </div>
      </div>

      <div className={`w-full ${view === 'grid' ? 'grid grid-cols-2 lg:grid-cols-3 gap-4' : 'space-y-4'}`}>
        {mediaItems.map((item) => (
          <div 
            key={item.id}
            className={`relative group cursor-pointer ${
              view === 'grid' 
                ? 'aspect-square bg-gray-100 dark:bg-gray-800 rounded-lg overflow-hidden' 
                : 'flex items-center p-2 border rounded-lg'
            }`}
            onClick={() => handleMediaSelect(item)}
          >
            <img
              src={item.url}
              alt={item.title}
              className={`${
                view === 'grid' 
                  ? 'w-full h-full object-cover' 
                  : 'w-20 h-20 object-cover rounded'
              }`}
            />
            
            <div className={`
              ${view === 'grid' 
                ? 'absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-30 transition-opacity flex items-center justify-center opacity-0 group-hover:opacity-100' 
                : 'ml-4'}
            `}>
              <span className={`
                ${view === 'grid' 
                  ? 'text-white' 
                  : 'text-gray-900 dark:text-gray-100'}
              `}>
                {item.title}
              </span>
            </div>
          </div>
        ))}
      </div>

      {selectedMedia && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-4 max-w-2xl w-full m-4">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-xl font-semibold">{selectedMedia.title}</h3>
              <button 
                onClick={() => setSelectedMedia(null)}
                className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded"
              >
                <Minimize2 className="h-5 w-5" />
              </button>
            </div>
            <img 
              src={selectedMedia.url} 
              alt={selectedMedia.title}
              className="w-full h-auto rounded"
            />
          </div>
        </div>
      )}
    </div>
  );
};

export default MediaGallery;
