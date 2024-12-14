import React, { useState, useCallback } from 'react';
import { ChevronLeft, ChevronRight, Maximize2, X } from 'lucide-react';

// Core Template System
const TemplateSystem = ({
  layout = 'default',
  content = {},
  media = [],
  className = ''
}) => {
  const [activeMedia, setActiveMedia] = useState(null);

  const layoutStyles = {
    default: 'max-w-4xl mx-auto',
    wide: 'max-w-6xl mx-auto',
    full: 'w-full'
  };

  return (
    <div className={`${layoutStyles[layout]} ${className}`}>
      {/* Content Zones */}
      <div className="space-y-8">
        {content.header && (
          <header className="prose max-w-none">
            {content.header}
          </header>
        )}

        <main className="grid grid-cols-1 md:grid-cols-12 gap-8">
          <div className="md:col-span-8">
            {content.main}
          </div>
          
          {content.sidebar && (
            <aside className="md:col-span-4">
              {content.sidebar}
            </aside>
          )}
        </main>

        {/* Media Gallery */}
        <MediaGallery 
          items={media} 
          onSelect={setActiveMedia}
        />
      </div>

      {/* Lightbox */}
      {activeMedia && (
        <MediaLightbox 
          item={activeMedia}
          onClose={() => setActiveMedia(null)}
        />
      )}
    </div>
  );
};

// Media Gallery Component
const MediaGallery = ({ items = [], onSelect }) => {
  const [page, setPage] = useState(0);
  const itemsPerPage = 12;
  const totalPages = Math.ceil(items.length / itemsPerPage);
  
  const visibleItems = items.slice(
    page * itemsPerPage,
    (page + 1) * itemsPerPage
  );

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        {visibleItems.map((item, index) => (
          <MediaItem 
            key={item.id || index}
            item={item}
            onSelect={onSelect}
          />
        ))}
      </div>
      
      {totalPages > 1 && (
        <Pagination
          currentPage={page}
          totalPages={totalPages}
          onPageChange={setPage}
        />
      )}
    </div>
  );
};

// Media Item Component
const MediaItem = ({ item, onSelect }) => (
  <div className="relative aspect-square group">
    <img
      src={item.thumbnail || item.url || '/api/placeholder/400/320'}
      alt={item.alt || ''}
      className="w-full h-full object-cover rounded-lg"
    />
    <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity">
      <button
        onClick={() => onSelect(item)}
        className="absolute inset-0 flex items-center justify-center"
      >
        <Maximize2 className="w-6 h-6 text-white" />
      </button>
    </div>
  </div>
);

// Media Lightbox Component
const MediaLightbox = ({ item, onClose }) => (
  <div className="fixed inset-0 z-50 bg-black/90 flex items-center justify-center">
    <button
      onClick={onClose}
      className="absolute top-4 right-4 text-white"
    >
      <X className="w-6 h-6" />
    </button>
    
    <img
      src={item.url || item.thumbnail}
      alt={item.alt || ''}
      className="max-h-[90vh] max-w-[90vw] object-contain"
    />
  </div>
);

// Pagination Component
const Pagination = ({ currentPage, totalPages, onPageChange }) => (
  <div className="flex justify-center items-center gap-4">
    <button
      onClick={() => onPageChange(Math.max(0, currentPage - 1))}
      disabled={currentPage === 0}
      className="p-2 disabled:opacity-50"
    >
      <ChevronLeft className="w-5 h-5" />
    </button>

    <span>
      Page {currentPage + 1} of {totalPages}
    </span>

    <button
      onClick={() => onPageChange(Math.min(totalPages - 1, currentPage + 1))}
      disabled={currentPage === totalPages - 1}
      className="p-2 disabled:opacity-50"
    >
      <ChevronRight className="w-5 h-5" />
    </button>
  </div>
);

export default TemplateSystem;
