import React, { useState, useCallback, useMemo } from 'react';
import { ChevronLeft, ChevronRight, X, Maximize2, Image as ImageIcon, AlertCircle } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

// Content Display Component
const ContentRenderer = ({ content = '', className = '' }) => {
  if (!content) return null;
  
  return (
    <div className={`prose max-w-none dark:prose-invert ${className}`}>
      {content}
    </div>
  );
};

// Image Gallery Component
const MediaGallery = ({ items = [], onSelect }) => {
  const [currentPage, setCurrentPage] = useState(1);
  const itemsPerPage = 12;

  const totalPages = Math.ceil(items.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const visibleItems = items.slice(startIndex, startIndex + itemsPerPage);

  if (!items.length) {
    return (
      <Alert>
        <AlertCircle className="h-4 w-4" />
        <AlertTitle>No Media</AlertTitle>
        <AlertDescription>No media items available to display.</AlertDescription>
      </Alert>
    );
  }

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        {visibleItems.map((item, index) => (
          <GalleryItem 
            key={`${item.id || index}`}
            item={item} 
            onSelect={onSelect}
          />
        ))}
      </div>
      
      {totalPages > 1 && (
        <Pagination 
          currentPage={currentPage}
          totalPages={totalPages}
          onPageChange={setCurrentPage}
        />
      )}
    </div>
  );
};

// Individual Gallery Item
const GalleryItem = ({ item, onSelect }) => {
  const handleError = (e) => {
    e.target.src = '/api/placeholder/400/320';
    e.target.alt = 'Image failed to load';
  };

  return (
    <div className="group relative aspect-square overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-800">
      <img
        src={item.thumbnail || item.url || '/api/placeholder/400/320'}
        alt={item.alt || 'Gallery image'}
        onError={handleError}
        className="h-full w-full object-cover transition-all hover:scale-105"
      />
      
      <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity">
        <div className="absolute inset-0 flex items-center justify-center gap-2">
          <button
            onClick={() => onSelect?.(item)}
            className="rounded-full bg-white/90 p-2 hover:bg-white transition-colors"
            aria-label="View full image"
          >
            <Maximize2 className="h-5 w-5" />
          </button>
        </div>
      </div>
    </div>
  );
};

// Lightbox Component
const Lightbox = ({ item, onClose }) => {
  if (!item) return null;

  return (
    <div className="fixed inset-0 z-50 bg-black/90 flex items-center justify-center">
      <button
        onClick={onClose}
        className="absolute top-4 right-4 text-white hover:text-gray-300"
        aria-label="Close lightbox"
      >
        <X className="h-6 w-6" />
      </button>
      
      <div className="max-w-7xl mx-auto px-4">
        <img
          src={item.url || item.thumbnail}
          alt={item.alt || 'Full size image'}
          className="max-h-[90vh] max-w-full object-contain"
        />
        
        {item.caption && (
          <div className="mt-4 text-center text-white">
            <p>{item.caption}</p>
          </div>
        )}
      </div>
    </div>
  );
};

// Pagination Component
const Pagination = ({ currentPage, totalPages, onPageChange }) => {
  return (
    <div className="flex items-center justify-center gap-4">
      <button
        onClick={() => onPageChange(Math.max(1, currentPage - 1))}
        disabled={currentPage === 1}
        className="p-2 rounded-full hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed"
        aria-label="Previous page"
      >
        <ChevronLeft className="h-5 w-5" />
      </button>

      <span className="text-sm">
        Page {currentPage} of {totalPages}
      </span>

      <button
        onClick={() => onPageChange(Math.min(totalPages, currentPage + 1))}
        disabled={currentPage === totalPages}
        className="p-2 rounded-full hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed"
        aria-label="Next page"
      >
        <ChevronRight className="h-5 w-5" />
      </button>
    </div>
  );
};

// Main Content Display Component
export default function ContentDisplay({ content = '', media = [], className = '' }) {
  const [selectedItem, setSelectedItem] = useState(null);
  
  // Memoize media array processing
  const mediaItems = useMemo(() => {
    return Array.isArray(media) ? media : [];
  }, [media]);

  // Callbacks
  const handleSelect = useCallback((item) => {
    setSelectedItem(item);
  }, []);

  const handleClose = useCallback(() => {
    setSelectedItem(null);
  }, []);

  return (
    <div className={`space-y-8 ${className}`}>
      {/* Content Section */}
      <ContentRenderer content={content} />

      {/* Media Gallery Section */}
      {mediaItems.length > 0 && (
        <div className="space-y-4">
          <h2 className="text-2xl font-bold">Media Gallery</h2>
          <MediaGallery 
            items={mediaItems} 
            onSelect={handleSelect}
          />
        </div>
      )}

      {/* Lightbox */}
      {selectedItem && (
        <Lightbox 
          item={selectedItem} 
          onClose={handleClose}
        />
      )}
    </div>
  );
}
