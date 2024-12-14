import React from 'react';
import { useState } from 'react';
import { Camera, Edit, Trash, AlertTriangle } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

const ContentDisplay = ({ content, options = {} }) => {
  const [isLoading, setIsLoading] = useState(false);

  const renderContent = () => {
    if (!content) {
      return (
        <Alert variant="destructive">
          <AlertTriangle className="h-4 w-4" />
          <AlertTitle>Error</AlertTitle>
          <AlertDescription>Content not available or invalid</AlertDescription>
        </Alert>
      );
    }

    return (
      <div className="w-full max-w-4xl mx-auto">
        <header className="mb-8">
          <h1 className="text-3xl font-bold mb-2">{content.title}</h1>
          {content.meta && (
            <div className="text-sm text-gray-500">
              {content.meta.author && <span>By {content.meta.author}</span>}
              {content.meta.date && <span> • {content.meta.date}</span>}
            </div>
          )}
        </header>

        <div className="prose max-w-none"
             dangerouslySetInnerHTML={{ __html: content.sanitizedBody }} />
        
        {content.media && (
          <MediaGallery media={content.media} options={options.gallery} />
        )}
      </div>
    );
  };

  return (
    <div className="p-4">
      {isLoading ? (
        <div className="flex justify-center items-center min-h-[200px]">
          <div className="w-8 h-8 animate-spin rounded-full border-4 border-primary border-t-transparent" />
        </div>
      ) : renderContent()}
    </div>
  );
};

const MediaGallery = ({ media, options = {} }) => {
  const [activeIndex, setActiveIndex] = useState(0);

  if (!media?.length) {
    return null;
  }

  return (
    <div className="mt-8">
      <div className="relative aspect-video mb-4">
        <img
          src={media[activeIndex].url}
          alt={media[activeIndex].alt}
          className="object-cover w-full h-full rounded-lg"
        />
      </div>
      
      <div className="grid grid-cols-6 gap-2">
        {media.map((item, index) => (
          <button
            key={item.id}
            onClick={() => setActiveIndex(index)}
            className={`relative aspect-square rounded-lg overflow-hidden ${
              index === activeIndex ? 'ring-2 ring-primary' : ''
            }`}
          >
            <img
              src={item.thumbnailUrl}
              alt={item.alt}
              className="object-cover w-full h-full"
            />
          </button>
        ))}
      </div>
    </div>
  );
};

const ContentEditor = ({ content, onSave }) => {
  const [formData, setFormData] = useState(content || {});
  const [errors, setErrors] = useState({});

  const handleSubmit = async (e) => {
    e.preventDefault();
    setErrors({});

    try {
      await onSave(formData);
    } catch (error) {
      setErrors(error.validationErrors || { general: error.message });
    }
  };

  return (
    <div className="max-w-4xl mx-auto p-4">
      <form onSubmit={handleSubmit}>
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium mb-1">Title</label>
            <input
              type="text"
              value={formData.title || ''}
              onChange={e => setFormData({ ...formData, title: e.target.value })}
              className="w-full p-2 border rounded-lg"
              maxLength={200}
            />
            {errors.title && (
              <p className="text-red-500 text-sm mt-1">{errors.title}</p>
            )}
          </div>

          <div>
            <label className="block text-sm font-medium mb-1">Content</label>
            <textarea
              value={formData.body || ''}
              onChange={e => setFormData({ ...formData, body: e.target.value })}
              className="w-full p-2 border rounded-lg min-h-[200px]"
            />
            {errors.body && (
              <p className="text-red-500 text-sm mt-1">{errors.body}</p>
            )}
          </div>

          <div className="flex justify-end space-x-2">
            <button
              type="button"
              className="px-4 py-2 border rounded-lg"
              onClick={() => setFormData(content || {})}
            >
              Reset
            </button>
            <button
              type="submit"
              className="px-4 py-2 bg-primary text-white rounded-lg"
            >
              Save Changes
            </button>
          </div>
        </div>
      </form>
    </div>
  );
};

export default { ContentDisplay, MediaGallery, ContentEditor };
