import React, { useState, useEffect } from 'react';
import { Image, Video, Document } from '@/components/media';
import { SecurityProvider, useSecurityContext } from '@/contexts/security';

const MediaRenderer = ({ media, context }) => {
  const security = useSecurityContext();
  const [processed, setProcessed] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    processMedia();
  }, [media]);

  const processMedia = async () => {
    try {
      await security.validateMedia(media);
      const processed = await processMediaItem(media);
      setProcessed(processed);
    } catch (error) {
      setError(error);
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return <LoadingPlaceholder />;
  }

  if (error) {
    return <ErrorDisplay error={error} />;
  }

  return (
    <div className="w-full h-full">
      {processed?.type === 'image' && (
        <Image
          src={processed.url}
          alt={processed.alt}
          className="w-full h-full object-cover"
          loading="lazy"
        />
      )}
      {processed?.type === 'video' && (
        <Video
          src={processed.url}
          poster={processed.poster}
          className="w-full h-full"
          controls
        />
      )}
      {processed?.type === 'document' && (
        <Document
          src={processed.url}
          title={processed.title}
          className="w-full h-full"
        />
      )}
    </div>
  );
};

const ResponsiveGallery = ({ items, layout }) => {
  const columns = layout.getResponsiveColumns();
  
  return (
    <div 
      className={`grid gap-4 grid-cols-1 md:grid-cols-${columns.md} lg:grid-cols-${columns.lg}`}
      style={{
        aspectRatio: layout.aspectRatio
      }}
    >
      {items.map((item, index) => (
        <div key={index} className="relative w-full h-full">
          <MediaRenderer media={item} context={layout.context} />
        </div>
      ))}
    </div>
  );
};

const LoadingPlaceholder = () => (
  <div className="w-full h-full animate-pulse bg-gray-200" />
);

const ErrorDisplay = ({ error }) => (
  <div className="w-full h-full flex items-center justify-center bg-red-50">
    <p className="text-red-500">{error.message}</p>
  </div>
);

export { MediaRenderer, ResponsiveGallery };