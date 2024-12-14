import React, { useState, useMemo } from 'react';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';

// Template Configuration Registry
const TemplateConfig = {
  DEFAULT: 'standard',
  LAYOUTS: {
    standard: {
      grid: 'grid-cols-1',
      zones: ['header', 'main', 'footer']
    },
    sidebar: {
      grid: 'grid-cols-1 md:grid-cols-12',
      zones: ['header', 'main:8', 'sidebar:4', 'footer']
    },
    fullwidth: {
      grid: 'grid-cols-1 w-full',
      zones: ['header', 'main', 'footer']
    }
  }
};

// Core Template Engine
const TemplateEngine = ({ 
  layout = TemplateConfig.DEFAULT,
  content = {},
  media = [],
  className = '' 
}) => {
  const [activeMedia, setActiveMedia] = useState(null);
  const [currentPage, setCurrentPage] = useState(0);

  const templateConfig = useMemo(() => 
    TemplateConfig.LAYOUTS[layout] || TemplateConfig.LAYOUTS.standard,
    [layout]
  );

  const renderZone = (zone) => {
    const [name, span] = zone.split(':');
    return (
      <div 
        key={name}
        className={`zone ${span ? `md:col-span-${span}` : 'col-span-full'}`}
      >
        <ContentRenderer content={content[name]} />
      </div>
    );
  };

  return (
    <div className={className}>
      <div className={`grid gap-8 ${templateConfig.grid}`}>
        {templateConfig.zones.map(renderZone)}
        <MediaGallery 
          items={media}
          currentPage={currentPage}
          onPageChange={setCurrentPage}
          onSelect={setActiveMedia}
        />
      </div>
      {activeMedia && (
        <MediaLightbox 
          media={activeMedia} 
          onClose={() => setActiveMedia(null)} 
        />
      )}
    </div>
  );
};

// Content Renderer Component
const ContentRenderer = ({ content }) => {
  if (!content) return null;

  return (
    <div className="prose max-w-none dark:prose-invert">
      {typeof content === 'string' ? (
        <div dangerouslySetInnerHTML={{ __html: content }} />
      ) : content}
    </div>
  );
};

// Media Gallery Component
const MediaGallery = ({ 
  items = [], 
  currentPage = 0,
  onPageChange,
  onSelect 
}) => {
  const ITEMS_PER_PAGE = 12;
  const totalPages = Math.ceil(items.length / ITEMS_PER_PAGE);
  const visibleItems = items.slice(
    currentPage * ITEMS_PER_PAGE,
    (currentPage + 1) * ITEMS_PER_PAGE
  );

  if (!items.length) return null;

  return (
    <div className="col-span-full space-y-6">
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        {visibleItems.map((item, index) => (
          <div 
            key={item.id || index}
            className="relative aspect-square group"
            onClick={() => onSelect(item)}
          >
            <img
              src={item.url || '/api/placeholder/400/320'}
              alt={item.alt || ''}
              className="w-full h-full object-cover rounded-lg"
            />
            <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity" />
          </div>
        ))}
      </div>

      {totalPages > 1 && (
        <div className="flex justify-center items-center gap-4">
          <button
            onClick={() => onPageChange(Math.max(0, currentPage - 1))}
            disabled={currentPage === 0}
            className="p-2 disabled:opacity-50"
          >
            <ChevronLeft className="w-5 h-5" />
          </button>
          <span>Page {currentPage + 1} of {totalPages}</span>
          <button
            onClick={() => onPageChange(Math.min(totalPages - 1, currentPage + 1))}
            disabled={currentPage === totalPages - 1}
            className="p-2 disabled:opacity-50"
          >
            <ChevronRight className="w-5 h-5" />
          </button>
        </div>
      )}
    </div>
  );
};

// Media Lightbox Component
const MediaLightbox = ({ media, onClose }) => (
  <div className="fixed inset-0 z-50 bg-black/90 flex items-center justify-center">
    <button
      onClick={onClose}
      className="absolute top-4 right-4 text-white"
    >
      <X className="w-6 h-6" />
    </button>
    <img
      src={media.url}
      alt={media.alt || ''}
      className="max-h-[90vh] max-w-[90vw] object-contain"
    />
  </div>
);

export default TemplateEngine;
